<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restore;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Contracts\PendingOperationActorResolver;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\LiveApprovalStore;
use Quraba\Backup\Operations\PendingOperationProcessor;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\TestCase;

final class OldSchemaPanelRestoreTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    protected function tearDown(): void
    {
        if ($this->app->maintenanceMode()->active()) {
            Artisan::call('up');
        }
        parent::tearDown();
    }

    public function test_panel_live_restore_of_old_schema_keeps_journal_success_without_pending_table(): void
    {
        Schema::drop('quraba_pending_operations');
        DB::table('migrations')->where('migration', '2026_10_04_000007_create_quraba_pending_operations_table')->delete();
        $source = $this->recoveryPointOfStateA();
        self::assertFalse(Schema::hasTable('quraba_pending_operations'));

        Artisan::call('migrate', ['--force' => true]);
        self::assertTrue(Schema::hasTable('quraba_pending_operations'));
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $this->config()->set('quraba-backup.filament.live_restore_enabled', true);
        $this->config()->set('quraba-backup.filament.authorization.live-restore', static fn (): bool => true);
        $actor = new GenericUser(['id' => 7]);
        $this->app->instance(PendingOperationActorResolver::class, new class($actor) implements PendingOperationActorResolver
        {
            public function __construct(private Authenticatable $actor) {}

            public function resolve(string $type, string $id): ?Authenticatable
            {
                return $this->actor;
            }
        });
        $nonce = bin2hex(random_bytes(32));
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, $actor::class, '7', $source->uuid, RestoreProfile::Full, 'old-schema', hash('sha256', $nonce));
        $this->app->make(LiveApprovalStore::class)->issue($operation, $nonce);

        $result = $this->app->make(PendingOperationProcessor::class)->runOne();

        self::assertNotNull($result);
        self::assertSame(PendingOperationStatus::Completed, $result->status);
        self::assertFalse(Schema::hasTable('quraba_pending_operations'));
        self::assertSame(RestoreJournal::TERMINAL_COMPLETED, $this->onlyJournal()->terminalState());
        self::assertSame('completed', $this->app->make(LiveApprovalStore::class)->history()[0]['worker_outcome']['status']);
        $this->assertDatabaseIsStateA();
        $this->assertMediaIsStateA();
        self::assertSame(1, Artisan::call('quraba:backup:pending-operations'));
        self::assertStringContainsString('php artisan migrate', Artisan::output());

        Artisan::call('migrate', ['--force' => true]);
        self::assertTrue(Schema::hasTable('quraba_pending_operations'));
        self::assertSame(0, PendingOperation::query()->count());
        self::assertNull($this->app->make(PendingOperationProcessor::class)->runOne());
    }
}
