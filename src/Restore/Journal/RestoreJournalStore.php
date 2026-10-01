<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Journal;

use Closure;
use InvalidArgumentException;
use JsonException;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\PrivateFile;
use Throwable;

/**
 * Durable storage of restore journals:
 * `{private root}/journal/{restore_uuid}.json`.
 *
 *  - outside the application database;
 *  - private: the directory is 0700, every version is first written to a
 *    file proven 0600 and owned by the current user;
 *  - atomic: write a temporary file, flush and fsync it, rename it over the
 *    journal, fsync the directory (where the platform allows), then read the
 *    journal back and compare it;
 *  - forward only: a version is written only when it is the direct successor
 *    of the version on disk ({@see RestoreJournal::assertSuccessorOf()});
 *  - never follows a symbolic link: a journal path or directory that is a
 *    link is refused.
 *
 * Any failure throws `restore.journal_failed`. Callers write the journal
 * BEFORE the mutation a record announces, so a journal that cannot be
 * written stops the restore before that mutation happens.
 */
final readonly class RestoreJournalStore
{
    private const int MAX_BYTES = 4194304;

    /** @var Closure(string, string): void */
    private Closure $writer;

    /**
     * @param  (Closure(string, string): void)|null  $writer  test seam: persists contents at a path atomically
     */
    public function __construct(private PackagePaths $paths, ?Closure $writer = null)
    {
        $this->writer = $writer ?? self::atomicWrite(...);
    }

    public function create(RestoreJournal $journal): RestoreJournal
    {
        $path = $this->pathFor($journal->restoreUuid());

        if (file_exists($path) || is_link($path)) {
            throw RestoreFailed::journalFailed('a journal already exists for this restore UUID');
        }

        if ($journal->sequence() !== 1) {
            throw RestoreFailed::journalFailed('a new journal must start at sequence 1');
        }

        return $this->write($path, $journal);
    }

    /**
     * Persists the next version of a journal.
     */
    public function save(RestoreJournal $journal): RestoreJournal
    {
        $path = $this->pathFor($journal->restoreUuid());
        $current = $this->read($path) ?? throw RestoreFailed::journalFailed('the journal to update does not exist');

        try {
            $journal->assertSuccessorOf($current);
        } catch (InvalidArgumentException $exception) {
            throw RestoreFailed::journalFailed('refusing a journal update that is not a forward step: '.$exception->getMessage());
        }

        return $this->write($path, $journal);
    }

    public function find(string $restoreUuid): ?RestoreJournal
    {
        return $this->read($this->pathFor($restoreUuid, create: false));
    }

    /**
     * Every journal on this host. A file that cannot be read as a journal is
     * returned separately: it is never ignored, because it may describe a
     * restore that changed the application.
     *
     * @return array{journals: list<RestoreJournal>, unreadable: list<string>}
     */
    public function all(): array
    {
        $directory = $this->paths->journal;
        $journals = [];
        $unreadable = [];

        if (is_link($directory)) {
            throw RestoreFailed::journalFailed('the journal directory is a symbolic link');
        }

        if (! is_dir($directory)) {
            return ['journals' => [], 'unreadable' => []];
        }

        $entries = @scandir($directory);

        if ($entries === false) {
            throw RestoreFailed::journalFailed('the journal directory cannot be read');
        }

        foreach ($entries as $entry) {
            if (! str_ends_with($entry, '.json')) {
                continue;
            }

            $uuid = substr($entry, 0, -5);

            try {
                if (! Identifiers::isUuid($uuid)) {
                    throw new InvalidArgumentException('unexpected file name');
                }

                $journal = $this->read($directory.'/'.$entry);

                if ($journal === null || $journal->restoreUuid() !== $uuid) {
                    throw new InvalidArgumentException('the journal does not match its file name');
                }

                $journals[] = $journal;
            } catch (Throwable) {
                $unreadable[] = $entry;
            }
        }

        usort($journals, static fn (RestoreJournal $a, RestoreJournal $b): int => $a->restoreUuid() <=> $b->restoreUuid());

        return ['journals' => $journals, 'unreadable' => $unreadable];
    }

    /**
     * Journals that block a further live restore.
     *
     * @return list<RestoreJournal>
     */
    public function unresolved(): array
    {
        return array_values(array_filter($this->all()['journals'], static fn (RestoreJournal $journal): bool => $journal->isUnresolved()));
    }

    public function directory(): string
    {
        return $this->paths->journal;
    }

    private function pathFor(string $restoreUuid, bool $create = true): string
    {
        $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
        $directory = $this->paths->journal;

        try {
            if ($create) {
                PackagePaths::ensureDirectory($directory);
                // The directory must really live inside the private root.
                PathGuard::assertRealWithin($directory, PackagePaths::ensureDirectory($this->paths->root));
            } elseif (is_link($directory)) {
                throw new InvalidArgumentException('the journal directory is a symbolic link');
            }
        } catch (RestoreFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RestoreFailed::journalFailed('the private journal directory cannot be used: '.$exception->getMessage(), $exception);
        }

        return $directory.'/'.$restoreUuid.'.json';
    }

    private function read(string $path): ?RestoreJournal
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            throw RestoreFailed::journalFailed('a journal path is a symbolic link; refusing to follow it');
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if (! is_string($contents) || strlen($contents) > self::MAX_BYTES) {
            throw RestoreFailed::journalFailed('a journal cannot be read');
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('not a JSON object');
            }

            return RestoreJournal::fromArray($decoded);
        } catch (JsonException|InvalidArgumentException $exception) {
            throw RestoreFailed::journalFailed('a journal is malformed: '.$exception->getMessage(), $exception);
        }
    }

    private function write(string $path, RestoreJournal $journal): RestoreJournal
    {
        if (is_link($path)) {
            throw RestoreFailed::journalFailed('a journal path is a symbolic link; refusing to write through it');
        }

        try {
            $contents = json_encode($journal->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            ($this->writer)($path, $contents);
        } catch (Throwable $exception) {
            throw RestoreFailed::journalFailed('the journal could not be written durably: '.$exception->getMessage(), $exception);
        }

        $stored = $this->read($path);

        if ($stored === null || $stored->sequence() !== $journal->sequence() || $stored->toArray() != $journal->toArray()) {
            throw RestoreFailed::journalFailed('the journal read back differs from the version written');
        }

        return $stored;
    }

    /**
     * The default writer: temporary private file → flush → fsync → rename →
     * fsync directory.
     */
    public static function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        $handle = PrivateFile::create($temporary);

        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new InvalidArgumentException('short write');
            }

            if (! fsync($handle)) {
                throw new InvalidArgumentException('fsync failed');
            }

            PrivateFile::assertStillPrivate($temporary, $handle);
        } catch (Throwable $exception) {
            fclose($handle);
            PrivateFile::destroy($temporary);

            throw $exception;
        }

        fclose($handle);

        if (is_link($path) || ! @rename($temporary, $path)) {
            PrivateFile::destroy($temporary);

            throw new InvalidArgumentException('the journal could not be moved into place');
        }

        // Make the rename itself durable where a directory can be synced.
        if (! PathGuard::isWindows()) {
            $directory = @fopen(dirname($path), 'r');

            if ($directory !== false) {
                @fsync($directory);
                fclose($directory);
            }
        }
    }
}
