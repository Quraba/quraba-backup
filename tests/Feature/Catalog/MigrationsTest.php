<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Catalog;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Tests\TestCase;

final class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_catalog_tables_exist_with_their_key_columns(): void
    {
        $expected = [
            'quraba_backup_runs' => ['uuid', 'profile', 'trigger', 'status', 'consistency', 'requested_at', 'started_at', 'completed_at', 'failed_at', 'failure_stage', 'failure_code', 'failure_message', 'pinned_until', 'pin_reason', 'metadata', 'created_at', 'updated_at'],
            'quraba_backup_artifacts' => ['backup_run_id', 'kind', 'status', 'storage', 'locator', 'snapshot_id', 'sha256', 'byte_size', 'verified_at', 'expired_at', 'failure_code', 'failure_message', 'metadata'],
            'quraba_restore_runs' => ['uuid', 'mode', 'profile', 'status', 'source_run_uuid', 'source_archive_locator', 'source_archive_sha256', 'source_snapshot_id', 'pre_change_run_uuid', 'requested_by_type', 'requested_by_id', 'destructive_started_at', 'completed_at', 'failed_at', 'failure_stage', 'failure_code', 'failure_message', 'metadata'],
            'quraba_backup_maintenance_runs' => ['uuid', 'operation', 'status', 'dry_run', 'planned_items', 'affected_items', 'started_at', 'finished_at', 'failure_stage', 'failure_code', 'failure_message', 'metadata'],
            'quraba_backup_settings' => ['key', 'value'],
        ];

        foreach ($expected as $table => $columns) {
            self::assertTrue(Schema::hasTable($table), $table);
            self::assertTrue(Schema::hasColumns($table, $columns), $table.' columns');
        }
    }

    public function test_settings_table_has_no_credential_columns(): void
    {
        foreach (Schema::getColumnListing('quraba_backup_settings') as $column) {
            self::assertDoesNotMatchRegularExpression('/password|secret|token|key_id|application_key/', $column);
        }
    }

    public function test_query_pattern_indexes_exist(): void
    {
        $names = static fn (string $table): array => array_map(static fn (array $index): string => $index['name'], Schema::getIndexes($table));

        self::assertContains('quraba_backup_runs_profile_status_completed_idx', $names('quraba_backup_runs'));
        self::assertContains('quraba_backup_runs_status_requested_idx', $names('quraba_backup_runs'));
        self::assertContains('quraba_backup_artifacts_run_kind_unique', $names('quraba_backup_artifacts'));
        self::assertContains('quraba_backup_artifacts_snapshot_unique', $names('quraba_backup_artifacts'));
        self::assertContains('quraba_restore_runs_status_created_idx', $names('quraba_restore_runs'));
        self::assertContains('quraba_maintenance_runs_op_status_created_idx', $names('quraba_backup_maintenance_runs'));
    }

    public function test_run_uuid_uniqueness_is_enforced_by_the_database(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);
        $row = (array) DB::table('quraba_backup_runs')->where('id', $run->id)->first();
        unset($row['id']);

        $this->expectException(QueryException::class);
        DB::table('quraba_backup_runs')->insert($row);
    }

    public function test_one_artifact_per_kind_per_run(): void
    {
        $run = BackupRun::request(BackupProfile::Recovery, BackupTrigger::Scheduled);
        $run->addArtifact(ArtifactKind::ApplicationArchive);
        $run->addArtifact(ArtifactKind::ResticSnapshot);

        $this->expectException(QueryException::class);
        $run->addArtifact(ArtifactKind::ApplicationArchive);
    }

    public function test_artifacts_do_not_cascade_away_with_their_run(): void
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual);
        $run->addArtifact(ArtifactKind::ResticSnapshot);

        $this->expectException(QueryException::class);
        DB::table('quraba_backup_runs')->where('id', $run->id)->delete();
    }
}
