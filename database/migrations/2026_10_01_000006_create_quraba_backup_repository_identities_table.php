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
        // The expected Restic repository identity per application/environment.
        // Not secret; immutable once established. A different repository at
        // the configured location is refused instead of silently adopted.
        Schema::connection($this->getConnection())->create('quraba_backup_repository_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('app_id');
            $table->string('environment', 32);
            $table->char('repository_id', 64);
            $table->string('location', 1024);
            $table->string('source', 32);
            $table->dateTime('established_at');
            $table->datetimes();

            $table->unique(['app_id', 'environment'], 'quraba_backup_repo_identity_app_env_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_backup_repository_identities');
    }
};
