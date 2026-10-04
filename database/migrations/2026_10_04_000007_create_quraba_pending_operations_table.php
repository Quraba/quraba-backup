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
        Schema::connection($this->getConnection())->create('quraba_pending_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('actor_type', 255);
            $table->string('actor_id', 255);
            $table->uuid('source_run_uuid')->nullable();
            $table->string('restore_profile', 32)->nullable();
            $table->char('approval_nonce_hash', 64)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->uuid('related_uuid')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->json('result')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->datetimes();
            $table->index(['status', 'requested_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('quraba_pending_operations');
    }
};
