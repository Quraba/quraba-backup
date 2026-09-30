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
        Schema::connection($this->getConnection())->create('quraba_backup_maintenance_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('operation', 32);
            $table->string('status', 32);
            $table->boolean('dry_run');

            $table->json('planned_items')->nullable();
            $table->json('affected_items')->nullable();

            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->string('failure_stage', 64)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->json('metadata')->nullable();
            $table->datetimes();

            // Last run of an operation, and stalled/indeterminate maintenance.
            $table->index(['operation', 'status', 'created_at'], 'quraba_maintenance_runs_op_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_backup_maintenance_runs');
    }
};
