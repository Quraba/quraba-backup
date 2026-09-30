<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('quraba-backup.database.catalog_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('quraba_restore_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('mode', 32);
            $table->string('profile', 32);
            $table->string('status', 32);

            // Exact frozen source identities. The source run is referenced by
            // UUID, not foreign key: after a clean-host restore the source run
            // may only be known from a remote manifest.
            $table->uuid('source_run_uuid');
            $table->string('source_archive_locator', 1024)->nullable();
            $table->char('source_archive_sha256', 64)->nullable();
            $table->char('source_snapshot_id', 64)->nullable();

            $table->uuid('pre_change_run_uuid')->nullable();

            $table->string('requested_by_type', 255)->nullable();
            $table->string('requested_by_id', 255)->nullable();

            $table->dateTime('destructive_started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();

            $table->string('failure_stage', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->json('metadata')->nullable();
            $table->datetimes();

            // Unresolved-restore detection (a second live restore is refused).
            $table->index(['status', 'created_at'], 'quraba_restore_runs_status_created_idx');
            $table->index('source_run_uuid', 'quraba_restore_runs_source_run_idx');
            // Safety backups referenced by restores must be protected from retention.
            $table->index('pre_change_run_uuid', 'quraba_restore_runs_pre_change_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_restore_runs');
    }
};
