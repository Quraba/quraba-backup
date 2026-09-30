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
}
