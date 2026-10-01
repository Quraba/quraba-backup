<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Structural guards for the process-execution invariants.
 */
final class ArchitectureTest extends TestCase
{
    /**
     * Classes allowed to start processes, and why. Restic is executed ONLY by
     * the ResticRunner; the others run bzip2, database client tools or PHP.
     */
    private const array PROCESS_CALLERS = [
        'Restic/ResticRunner.php',                  // the single Restic boundary
        'Restic/Installer/Bzip2Decompressor.php',   // bzip2 -dc fallback
        'Database/DatabaseToolLocator.php',         // mysqldump/mariadb --version
        'Health/Doctor/Checks/RuntimeChecks.php',   // php -r probe
        'Health/Doctor/Checks/LockingChecks.php',   // php -r cross-process flock probe
        'Archive/Database/ArgvMySqlDumper.php',     // mariadb-dump/mysqldump (argument array)
        'Archive/Database/MySqlDatabaseDumper.php', // passes the factory to the dumper
        'Restore/RestoreDatabaseValidator.php',     // optional dedicated scratch DB import
        'Restore/Live/ExactDatabaseReplacement.php', // live import: mysql/mariadb client (argument array)
        'Support/Process/ProcessFactory.php',
        'Support/Process/SymfonyProcessFactory.php',
        'QurabaBackupServiceProvider.php',
    ];

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sources(): array
    {
        $root = dirname(__DIR__, 2).'/src/';
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    public function test_no_shell_execution_functions_are_used(): void
    {
        $forbidden = ['shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec'];

        foreach (self::sources() as $path => $contents) {
            // Token-based: ignores string literals and comments.
            $tokens = array_values(array_filter(
                token_get_all($contents),
                static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));

            foreach ($tokens as $index => $token) {
                if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $name = strtolower(ltrim($token[1], '\\'));
                $previous = $tokens[$index - 1] ?? null;
                $next = $tokens[$index + 1] ?? null;
                $isMethodOrDeclaration = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

                self::assertFalse(
                    in_array($name, $forbidden, true) && $next === '(' && ! $isMethodOrDeclaration,
                    sprintf('%s calls %s(); commands must go through Symfony Process argument arrays.', $path, $name),
                );

                self::assertFalse(is_array($token) && $token[1] === 'fromShellCommandline', $path.' must not build shell command lines.');
            }

            self::assertFalse(in_array('`', $tokens, true), $path.' must not use backtick execution.');
        }
    }

    public function test_symfony_process_is_only_instantiated_by_the_factory(): void
    {
        foreach (self::sources() as $path => $contents) {
            if ($path !== 'Support/Process/SymfonyProcessFactory.php') {
                self::assertStringNotContainsString('new Process(', $contents, $path);
            }
        }
    }

    public function test_only_reviewed_classes_can_start_processes(): void
    {
        $callers = [];

        foreach (self::sources() as $path => $contents) {
            if (str_contains($contents, 'ProcessFactory')) {
                $callers[] = $path;
            }
        }

        sort($callers);
        $expected = self::PROCESS_CALLERS;
        sort($expected);

        self::assertSame($expected, $callers, 'A new class starts processes; review it and update the allowlist deliberately.');
    }

    public function test_every_package_command_uses_the_quraba_backup_namespace(): void
    {
        $signatures = 0;

        foreach (self::sources() as $path => $contents) {
            if (preg_match('/protected \$signature = \'([^\s\']+)/', $contents, $matches) === 1) {
                $signatures++;
                self::assertStringStartsWith('quraba:backup:', $matches[1], $path.' must use the quraba:backup:* namespace.');
            }
        }

        self::assertGreaterThanOrEqual(10, $signatures);
    }

    public function test_no_package_code_claims_or_references_bare_backup_commands(): void
    {
        $roots = [dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/config'];

        foreach ($roots as $root) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // Bare `backup:*` names belong to spatie/laravel-backup.
                self::assertDoesNotMatchRegularExpression(
                    '/(?<![a-z:_-])backup:[a-z]/',
                    (string) file_get_contents($file->getPathname()),
                    $file->getPathname().' references a bare backup:* command.',
                );
            }
        }
    }

    public function test_spatie_is_only_used_behind_the_archive_engine_boundary(): void
    {
        $users = [];

        foreach (self::sources() as $path => $contents) {
            if (preg_match('/\bSpatie\\\\/', $contents) === 1) {
                $users[] = $path;
            }
        }

        sort($users);

        self::assertSame(['Archive/Database/ArgvMySqlDumper.php', 'Archive/SpatieArchiveEngine.php'], $users);
    }

    public function test_object_stores_never_address_the_restic_prefix(): void
    {
        $sources = self::sources();

        foreach (['Archive/ArchiveStore.php', 'Manifest/ManifestStore.php'] as $store) {
            self::assertStringNotContainsString('resticRoot', $sources[$store], $store);
            self::assertStringContainsString('assertManaged(', $sources[$store], $store.' must validate every remote path.');
        }

        // The object storage implementation refuses the Restic prefix itself.
        self::assertStringContainsString('forbiddenPrefixes', $sources['Storage/FlysystemObjectStorage.php']);
        self::assertStringContainsString('resticRoot()', $sources['Storage/ObjectStorageFactory.php']);
    }

    public function test_only_the_runner_executes_restic_and_it_exposes_no_raw_api(): void
    {
        foreach (self::sources() as $path => $contents) {
            if ($path !== 'Restic/ResticRunner.php') {
                self::assertStringNotContainsString('->binary()->path', $contents, $path.' must not build Restic commands.');
            }
        }
    }

    public function test_no_arbitrary_restic_command_api_exists(): void
    {
        $runner = self::sources()['Restic/ResticRunner.php'];

        self::assertDoesNotMatchRegularExpression('/public function \w*(arbitrary|raw|command|execute)\w*\s*\(/i', $runner);
        self::assertMatchesRegularExpression('/private function execute\(/', $runner);
    }

    public function test_remote_delete_is_only_called_by_retention(): void
    {
        foreach (self::sources() as $path => $contents) {
            if ($path !== 'Storage/FlysystemObjectStorage.php' && str_contains($contents, '->delete(')) {
                self::assertSame('Retention/RetentionExecutor.php', $path, 'Remote deletion must remain inside retention.');
            }
        }

        $runner = self::sources()['Restic/ResticRunner.php'];
        self::assertSame(2, substr_count($runner, "'--target', \$target"), 'Restic restores only into a workspace or a proven staging area.');
        self::assertSame(2, substr_count($runner, '$snapshotId = Identifiers::assertFullSnapshotId($snapshotId);'), 'Every restore operation requires a full snapshot ID.');
        self::assertSame(1, substr_count($runner, 'private function restoreRootInto('), 'The only arbitrary-target restore is private.');
        self::assertStringContainsString('OperationWorkspace $workspace', $runner);
        self::assertStringContainsString('MediaStagingArea $area', $runner);
        $scratch = self::sources()['Restore/RestoreDatabaseValidator.php'];
        self::assertStringContainsString('SELECT DATABASE() AS db', $scratch);
        self::assertStringContainsString('$productionDb === $scratchDb', $scratch);
    }

    /**
     * The destructive boundary can only be crossed by the live restore
     * service; nothing else may call the mutating primitives.
     */
    public function test_only_the_live_restore_service_crosses_the_destructive_boundary(): void
    {
        $orchestrator = 'Restore/Live/LiveRestoreService.php';
        $mutations = [
            '->database->clear(' => [$orchestrator],
            '->database->import(' => [$orchestrator],
            '->directories->park(' => [$orchestrator],
            '->directories->activate(' => [$orchestrator],
            "withDatabaseState('clear_starting'" => [$orchestrator],
            "'swap_starting')" => [$orchestrator],
            'markApplying(' => [$orchestrator, 'Models/RestoreRun.php'],
            'markQuiescing(' => [$orchestrator, 'Models/RestoreRun.php'],
            'markSafetyBackup(' => [$orchestrator, 'Models/RestoreRun.php'],
            // Emptying a database: the live replacement and the scratch validation only.
            '->schema->drop(' => ['Restore/Live/ExactDatabaseReplacement.php', 'Restore/RestoreDatabaseValidator.php'],
            'runSafetyBackup(' => ['Backup/BackupManager.php', 'Restore/Live/SafetyBackupService.php'],
        ];

        foreach (self::sources() as $path => $contents) {
            foreach ($mutations as $needle => $allowed) {
                if (str_contains($contents, $needle)) {
                    self::assertContains($path, $allowed, sprintf('%s must not use %s.', $path, $needle));
                }
            }

            foreach (['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE'] as $statement) {
                // Mentioned in comments and in the dump inspector's refusal list only.
                if ($path !== 'Database/DumpInspector.php') {
                    self::assertStringNotContainsString($statement, self::code($contents), $path.' must never issue '.$statement.'.');
                }
            }
        }

        // Only the classes of the live restore itself may depend on the
        // database replacement contract.
        $users = array_keys(array_filter(self::sources(), static fn (string $c): bool => str_contains($c, 'DatabaseReplacement $database')));
        sort($users);
        self::assertSame(['Restore/Live/LiveRestoreService.php', 'Restore/Live/RestoreReconciler.php'], $users);
        self::assertStringNotContainsString('->database->clear(', self::sources()['Restore/Live/RestoreReconciler.php'].'');
    }

    /**
     * Journal first, safety verified, then mutate — in that textual order.
     */
    public function test_database_and_media_mutations_follow_the_journal_and_the_safety_backup(): void
    {
        $service = self::sources()['Restore/Live/LiveRestoreService.php'];
        $order = static function (string ...$needles) use ($service): void {
            $position = -1;

            foreach ($needles as $needle) {
                $found = strpos($service, $needle, max(0, $position));
                self::assertNotFalse($found, 'Missing in LiveRestoreService: '.$needle);
                self::assertGreaterThan($position, $found, $needle.' is out of order.');
                $position = $found;
            }
        };

        // execute(): no unresolved restore → quiescence proven → safety backup → re-verification → boundary.
        $order('$this->assertNoUnresolvedRestore();', '$this->preparation->prepare(', '$this->quiescence->enter()', '$this->safety->take(', '$this->reverify(', 'withPhase(JournalPhase::Applying)', '$this->replaceDatabase(', '$this->replaceRoot(', '$this->verifyAll(', 'RestoreJournal::TERMINAL_COMPLETED');
        // database: boundary record → clear → record → record → import → record → verify.
        $order("withDatabaseState('clear_starting')", '$this->database->clear(', "withDatabaseState('cleared'", "withDatabaseState('import_starting')", '$this->database->import(', "withDatabaseState('import_completed')", '$this->database->verify(', "withDatabaseState('verified'");
        // media root: boundary record → park → record → activate → record → verify.
        $order("withRootState(\$name, 'swap_starting')", '$this->directories->park(', "withRootState(\$name, 'parked')", '$this->directories->activate(', "withRootState(\$name, 'activated')", '$this->verifyRoot(', "withRootState(\$name, 'verified'");

        self::assertStringContainsString('safetyVerified()', $service, 'The boundary is refused without a verified safety backup.');
        self::assertStringContainsString('ConsistencyLevel::Quiesced', $service, 'A live restore never runs at best_effort consistency.');
        self::assertStringNotContainsString('BestEffort', $service);

        // No automatic rollback: the service never restores a safety backup,
        // never renames a parked tree back and never repeats the import.
        self::assertSame(1, substr_count($service, '$this->database->import('));
        self::assertSame(1, substr_count($service, '$this->directories->park('));
        self::assertSame(1, substr_count($service, '$this->directories->activate('));
        self::assertStringNotContainsString("->call('up'", $service);
    }

    public function test_media_replacement_is_rename_only_without_copy_or_overlay(): void
    {
        foreach (self::sources() as $path => $contents) {
            if (! str_starts_with($path, 'Restore/')) {
                continue;
            }

            $code = self::code($contents);

            foreach (['copy(', 'copyDirectory(', 'symlink(', 'File::copy', 'stream_copy_to_stream('] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $code, $path.' must not copy data into place; media roots are replaced by rename only.');
            }
        }

        $replacement = self::code(self::sources()['Restore/Live/ExactDirectoryReplacement.php']);
        self::assertSame(2, substr_count($replacement, '@rename('), 'Exactly two renames: live to parked, staged to live.');
        self::assertStringNotContainsString('mkdir(', $replacement);
        self::assertStringNotContainsString('file_put_contents(', $replacement);
        self::assertStringNotContainsString('unlink(', $replacement);
    }

    public function test_restores_never_use_short_or_latest_snapshot_selectors(): void
    {
        foreach (self::sources() as $path => $contents) {
            if (str_starts_with($path, 'Restore/') || str_starts_with($path, 'Recovery/') || $path === 'Console/RestoreCommand.php') {
                self::assertDoesNotMatchRegularExpression('/[\'"]latest[\'"]/', self::code($contents), $path.' must resolve exact identities only.');
                self::assertStringNotContainsString('short_id', $contents, $path);
            }
        }

        $journal = self::sources()['Restore/Journal/RestoreJournal.php'];
        self::assertStringContainsString("'snapshot_id' => Identifiers::isFullSnapshotId(...)", $journal);
        self::assertStringContainsString("'repository_id' => Identifiers::isRepositoryId(...)", $journal);
        self::assertStringContainsString('Identifiers::assertUuid($runUuid', self::sources()['Restore/RestoreSourceResolver.php']);
    }

    public function test_a_normal_restore_never_writes_the_live_env_file(): void
    {
        foreach (self::sources() as $path => $contents) {
            if (str_starts_with($path, 'Restore/')) {
                self::assertStringNotContainsString('environmentFilePath', $contents, $path.' must not know where the live .env is.');
                self::assertStringNotContainsString('environmentPath', $contents, $path);
                self::assertDoesNotMatchRegularExpression('/[\'"]\.env[\'"]/', self::code($contents), $path.' must not address a .env file.');
            }
        }

        // A restore verifies the archived .env but never extracts it: only the
        // SQL dump leaves the archive.
        $reconstructor = self::sources()['Restore/ArchiveReconstructor.php'];
        self::assertSame(1, substr_count(self::code($reconstructor), '$this->copyEntry('));
        self::assertStringContainsString('$this->copyEntry($zip, SpatieArchiveEngine::DATABASE_ENTRY', $reconstructor);
        // The explicit bootstrap command is the only writer of an .env, through PrivateFile.
        $bootstrap = self::sources()['Recovery/EnvBootstrapper.php'];
        self::assertStringContainsString('PrivateFile::create($destination)', $bootstrap);
        self::assertStringNotContainsString('file_put_contents(', $bootstrap);
    }

    public function test_database_credentials_never_reach_the_argument_list_of_the_import(): void
    {
        foreach (['Restore/Live/ExactDatabaseReplacement.php', 'Restore/RestoreDatabaseValidator.php', 'Archive/Database/ArgvMySqlDumper.php'] as $path) {
            $code = self::code(self::sources()[$path]);
            self::assertStringContainsString("'--defaults-extra-file='", $code, $path.' passes credentials through a private option file.');
            self::assertStringContainsString('MySqlOptionFile::write(', $code, $path);
            self::assertStringContainsString('MySqlOptionFile::destroy(', $code, $path.' removes the option file again.');

            foreach (["'--password", "'-p", "'--user=", "'-u", 'MYSQL_PWD'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $code, $path.' must not put credentials into argv or the environment.');
            }
        }

        // The dump is streamed to the client's stdin; it is never an argument and never a shell redirect.
        $import = self::code(self::sources()['Restore/Live/ExactDatabaseReplacement.php']);
        self::assertStringContainsString('$process->setInput(DefinerFilter::stream($stream', $import);
        self::assertStringContainsString("'--database='.\$target->database", $import);
        self::assertStringNotContainsString('--execute', $import);
        self::assertStringNotContainsString("'-e'", $import);
        self::assertStringContainsString('ChildEnvironment::build()', $import);
    }

    public function test_safety_backups_use_the_backup_manager_with_the_pre_restore_trigger(): void
    {
        $manager = self::sources()['Backup/BackupManager.php'];
        $method = substr($manager, (int) strpos($manager, 'public function runSafetyBackup('), 1400);
        self::assertStringContainsString('BackupTrigger::PreRestore', $method);
        self::assertStringContainsString('$run->pin(', $method, 'A safety backup is pinned the moment it is requested.');
        self::assertStringContainsString('$locks->holds(LockName::GlobalOperation)', $method);
        self::assertStringContainsString('$locks->holds(LockName::Restore)', $method);

        // No second backup architecture: the safety service only delegates.
        $safety = self::sources()['Restore/Live/SafetyBackupService.php'];
        self::assertStringContainsString('$this->backups->runSafetyBackup(', $safety);
        foreach (['ResticRunner', 'ArchiveEngine', 'DatabaseDumper', 'BackupRun::request('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $safety, 'The safety backup must go through the BackupManager.');
        }
    }

    public function test_the_dry_run_cannot_reach_live_mutation_services(): void
    {
        $sources = self::sources();

        foreach (['Restore/RestoreDryRunService.php', 'Restore/RestorePreparation.php', 'Restore/RestorePreflight.php', 'Restore/ArchiveReconstructor.php', 'Restore/RestoreDatabaseValidator.php', 'Restore/RestoreSourceResolver.php'] as $path) {
            foreach (['LiveRestoreService', 'DatabaseReplacement', 'ExactDirectoryReplacement', 'SafetyBackupService', 'RestoreJournalStore', 'beginLiveRestore', 'beginWriteOperation'] as $live) {
                self::assertStringNotContainsString($live, $sources[$path], $path.' must stay non-destructive.');
            }
        }

        self::assertStringContainsString('beginRestorePreparation(', $sources['Restore/RestoreDryRunService.php']);
        self::assertStringContainsString('beginLiveRestore(', $sources['Restore/Live/LiveRestoreService.php']);

        // The command resolves the live service lazily, only on the live path,
        // and only behind the authorization object.
        $command = $sources['Console/RestoreCommand.php'];
        self::assertStringNotContainsString('public function handle(LiveRestoreService', $command);
        self::assertStringContainsString('LiveRestoreAuthorization::confirm(', $command);
        self::assertStringContainsString('LiveRestoreAuthorization $authorization', $sources['Restore/Live/LiveRestoreService.php']);
        self::assertStringContainsString('private function __construct', $sources['Restore/Live/LiveRestoreAuthorization.php']);
    }

    /**
     * Source with comments and docblocks removed.
     */
    private static function code(string $contents): string
    {
        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
