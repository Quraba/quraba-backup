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
        // Safe operational settings only. Credentials and deployment paths are
        // never stored in the database.
        Schema::connection($this->getConnection())->create('quraba_backup_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191)->unique();
            $table->json('value')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_backup_settings');
    }
};
