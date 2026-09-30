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
        Schema::connection($this->getConnection())->create('quraba_backup_artifacts', function (Blueprint $table): void {
            $table->id();

            // Catalog rows are never cascaded away: deleting a run must not
            // silently erase the record of its physical artifacts.
            $table->foreignId('backup_run_id')
                ->constrained('quraba_backup_runs')
                ->restrictOnDelete();

            $table->string('kind', 32);
            $table->string('status', 32);
            $table->string('storage', 32);

            $table->string('locator', 1024)->nullable();
            $table->char('snapshot_id', 64)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();

            $table->dateTime('verified_at')->nullable();
            $table->dateTime('expired_at')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->json('metadata')->nullable();
            $table->datetimes();

            // One artifact of each kind per run: retries adopt, never duplicate.
            $table->unique(['backup_run_id', 'kind'], 'quraba_backup_artifacts_run_kind_unique');
            // A Restic snapshot belongs to exactly one artifact.
            $table->unique('snapshot_id', 'quraba_backup_artifacts_snapshot_unique');
            // Latest verified artifact of a kind (health, retention).
            $table->index(['kind', 'status', 'verified_at'], 'quraba_backup_artifacts_kind_status_verified_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_backup_artifacts');
    }
};
