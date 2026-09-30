<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive\Database;

use InvalidArgumentException;
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
 *  - credentials only in a 0600 option file inside the operation workspace,
 *    passed with --defaults-extra-file and deleted immediately afterwards;
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

    private function writeCredentialsFile(): string
    {
        $path = rtrim($this->credentialsDirectory, '/').'/client-'.bin2hex(random_bytes(8)).'.cnf';
        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            throw CannotStartDump::emptyParameter('credentials file');
        }

        @chmod($path, 0600);

        $lines = ['[client]', 'user='.self::quote($this->userName), 'password='.self::quote($this->password)];

        if ($this->socket !== '') {
            $lines[] = 'socket='.self::quote($this->socket);
        } else {
            $lines[] = 'host='.self::quote($this->host);
            $lines[] = 'port='.$this->port;
        }

        fwrite($handle, implode("\n", $lines)."\n");
        fclose($handle);

        return $path;
    }

    private function destroyCredentialsFile(string $path): void
    {
        if (is_file($path)) {
            $size = (int) @filesize($path);
            @file_put_contents($path, str_repeat("\0", max(1, $size)));
            @unlink($path);
        }
    }

    /**
     * Quotes a value for a MySQL option file. The client strips only the
     * outer quotes and interprets backslash escapes (unknown ones keep the
     * backslash), so only backslashes are escaped; inner quotes and "#" are
     * literal inside a quoted value. Line breaks cannot be represented.
     */
    public static function quote(#[\SensitiveParameter] string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('Database credentials containing line breaks cannot be written to a MySQL option file.');
        }

        return '"'.str_replace('\\', '\\\\', $value).'"';
    }
}
