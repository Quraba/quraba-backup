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
        Schema::connection($this->getConnection())->create('quraba_backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('profile', 32);
            $table->string('trigger', 32);
            $table->string('status', 32);
            $table->string('consistency', 32);

            // All instants are stored as UTC wall-clock values in DATETIME
            // columns (never TIMESTAMP, which converts via the session timezone).
            $table->dateTime('requested_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();

            $table->string('failure_stage', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->dateTime('pinned_until')->nullable();
            $table->string('pin_reason', 255)->nullable();

            $table->json('metadata')->nullable();
            $table->datetimes();

            // Latest successful run per profile (health, retention).
            $table->index(['profile', 'status', 'completed_at'], 'quraba_backup_runs_profile_status_completed_idx');
            // Oldest pending/active run first (scheduler pickup, reconciliation).
            $table->index(['status', 'requested_at'], 'quraba_backup_runs_status_requested_idx');
            // Pre-restore safety backups and trigger-scoped listings.
            $table->index(['trigger', 'created_at'], 'quraba_backup_runs_trigger_created_idx');
            // Retention must find pinned runs cheaply.
            $table->index('pinned_until', 'quraba_backup_runs_pinned_until_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_backup_runs');
    }
};
