<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Pages\Page;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Restore\RestoreDryRunService;
use Quraba\Backup\Retention\RetentionExecutor;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class BackupOperations extends Page
{
    protected string $view = 'quraba-backup::filament.operations';

    protected static ?string $navigationLabel = 'Recovery operations';

    public string $restoreRunUuid = '';

    public string $restoreProfile = 'full';

    /** @var array<string, mixed>|null */
    public ?array $dryRunResult = null;

    /** @var array<string, mixed>|null */
    public ?array $retentionResult = null;

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-recovery');
    }

    public function runDryRestore(): void
    {
        BackupPanelAccess::authorize('dry-restore');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/iD', $this->restoreRunUuid) === 1, 422);
        $profile = RestoreProfile::tryFrom($this->restoreProfile);
        abort_if($profile === null, 422);

        try {
            $this->dryRunResult = app(RestoreDryRunService::class)->run($this->restoreRunUuid, $profile);
        } catch (Throwable $exception) {
            $this->dryRunResult = ['ok' => false, 'error' => app(SecretRedactor::class)->redact($exception->getMessage())];
        }
    }

    public function planRetention(): void
    {
        BackupPanelAccess::authorize('plan-retention');

        try {
            $this->retentionResult = app(RetentionExecutor::class)->run(false)->toArray();
        } catch (Throwable $exception) {
            $this->retentionResult = ['ok' => false, 'error' => app(SecretRedactor::class)->redact($exception->getMessage())];
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-recovery');

        return [
            'maintenance' => BackupMaintenanceRun::query()->latest('id')->limit(20)->get(),
            'canDryRestore' => BackupPanelAccess::allows('dry-restore'),
            'canPlanRetention' => BackupPanelAccess::allows('plan-retention'),
            'secretState' => [
                'B2 key ID' => filled(config('quraba-backup.storage.b2.key_id')),
                'B2 application key' => filled(config('quraba-backup.storage.b2.application_key')),
                'Archive password' => filled(config('quraba-backup.archive.password')),
                'Restic password' => filled(config('restic.password')) || filled(config('restic.password_file')),
            ],
        ];
    }
}
