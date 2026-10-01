<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive\Database;

use InvalidArgumentException;
use Quraba\Backup\Database\MySqlOptionFile;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Exceptions\CannotStartDump;

/**
 * Spatie's MySQL/MariaDB dumper with a safe executor.
 *
 * Spatie db-dumper 4.x builds its command as a single shell string
 * (Process::fromShellCommandline, with shell redirection and pipes) and
 * defaults to an unlimited timeout. That breaks this package's invariants
 * (no shell, bounded processes), so this subclass keeps Spatie's dumper
 * configuration surface and success checks but executes the dump itself:
 *
 *  - an argument array, run through the package ProcessFactory (no shell);
 *  - credentials only in an option file inside the operation workspace that
 *    is proven private (0600, owner) before they are written, passed with
 *    --defaults-extra-file and destroyed immediately afterwards;
 *  - the dump written by the tool itself via --result-file (streamed to
 *    disk, never through PHP memory);
 *  - a minimal child environment and a positive timeout.
 */
final class ArgvMySqlDumper extends MySql
{
    private ?ProcessFactory $processes = null;

    private string $binary = '';

    private string $credentialsDirectory = '';

    private float $processTimeout = 0.0;

    private bool $oracleClient = false;

    private bool $oracleClient8 = false;

    public static function using(ProcessFactory $processes, string $binary, string $versionLine, string $credentialsDirectory, int $timeoutSeconds): self
    {
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('The database dump timeout must be positive.');
        }

        $dumper = new self;
        $dumper->processes = $processes;
        $dumper->binary = $binary;
        $dumper->credentialsDirectory = $credentialsDirectory;
        $dumper->processTimeout = (float) $timeoutSeconds;
        $dumper->oracleClient = ! str_contains(strtolower($versionLine), 'mariadb');
        $dumper->oracleClient8 = $dumper->oracleClient && preg_match('/\bVer 8\.|\b8\.\d+\.\d+/', $versionLine) === 1;

        return $dumper;
    }

    public function dumpToFile(string $dumpFile): void
    {
        $this->guardAgainstIncompleteCredentials();

        if ($this->processes === null || $this->binary === '') {
            throw CannotStartDump::emptyParameter('binary');
        }

        if ($this->dbName === '' || str_starts_with($this->dbName, '-')) {
            throw new InvalidArgumentException('Invalid database name for dumping.');
        }

        $credentials = $this->writeCredentialsFile();

        try {
            $process = $this->processes->make($this->arguments($dumpFile, $credentials), null, ChildEnvironment::build(), $this->processTimeout);
            $process->run();

            $this->checkIfDumpWasSuccessful($process, $dumpFile);
        } finally {
            $this->destroyCredentialsFile($credentials);
        }
    }

    /**
     * @return non-empty-list<string>
     */
    public function arguments(string $dumpFile, string $credentialsFile): array
    {
        $arguments = [
            $this->binary,
            // Must be the first option.
            '--defaults-extra-file='.$credentialsFile,
            // Consistent InnoDB snapshot without global locks; stream rows.
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--hex-blob',
            '--triggers',
            $this->useExtendedInserts ? '--extended-insert' : '--skip-extended-insert',
        ];

        if ($this->skipComments) {
            $arguments[] = '--skip-comments';
        }

        if ($this->includeRoutines) {
            $arguments[] = '--routines';
        }

        if ($this->defaultCharacterSet !== '' && preg_match('/^[A-Za-z0-9_]+$/', $this->defaultCharacterSet) === 1) {
            $arguments[] = '--default-character-set='.$this->defaultCharacterSet;
        }

        if ($this->oracleClient) {
            // GTID statements break imports into other servers (shared hosting).
            $arguments[] = '--set-gtid-purged=OFF';
        }

        if ($this->oracleClient8) {
            // MySQL 8 clients otherwise query column statistics MariaDB lacks.
            $arguments[] = '--column-statistics=0';
        }

        $arguments[] = '--result-file='.$dumpFile;
        $arguments[] = '--';
        $arguments[] = $this->dbName;

        return $arguments;
    }

    /**
     * Refuses (WorkspaceViolation) when the file cannot be proven private.
     */
    private function writeCredentialsFile(): string
    {
        return MySqlOptionFile::write(
            $this->credentialsDirectory,
            $this->userName,
            $this->password,
            $this->host,
            $this->port,
            $this->socket === '' ? null : $this->socket,
        );
    }

    private function destroyCredentialsFile(string $path): void
    {
        MySqlOptionFile::destroy($path);
    }

    /**
     * @see MySqlOptionFile::quote()
     */
    public static function quote(#[\SensitiveParameter] string $value): string
    {
        return MySqlOptionFile::quote($value);
    }
}
