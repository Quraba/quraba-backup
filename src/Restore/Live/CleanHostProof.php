<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Quraba\Backup\Database\DatabaseTarget;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Restore\MediaRootMapping;

/**
 * Proof for a DECLARED clean-host restore (`--clean-host`) that there is
 * nothing a safety backup could preserve:
 *
 *  - the target database exists and holds no schema object at all;
 *  - every media destination being restored is absent or empty (framework
 *    placeholder files such as `.gitignore` do not count as content).
 *
 * Only then may the journal record
 * `safety_backup = not_required_target_proven_empty`. The mode is never
 * inferred — missing catalog tables alone prove nothing — and one stray
 * table or file refuses it.
 */
final readonly class CleanHostProof
{
    public const array PLACEHOLDERS = ['.gitignore', '.gitkeep'];

    /**
     * @param  list<MediaRootMapping>  $media
     * @return array<string, mixed> evidence
     *
     * @throws RestoreFailed
     */
    public function prove(RestoreProfile $profile, ?DatabaseTarget $target, ?SchemaInventory $inventory, array $media): array
    {
        $evidence = ['proven_at_profile' => $profile->value];

        if ($profile !== RestoreProfile::Media) {
            if ($target === null || $inventory === null) {
                throw RestoreFailed::cleanHostRefused('the target database could not be inspected');
            }

            if (! $inventory->isEmpty()) {
                throw RestoreFailed::cleanHostRefused(sprintf('the target database [%s] already holds %d schema object(s); a clean-host restore needs an empty database. Restore without --clean-host to take a safety backup first', $target->database, $inventory->count()));
            }

            $evidence['database'] = ['name' => $target->database, 'server' => $target->server, 'objects' => 0];
        }

        if ($profile !== RestoreProfile::Database) {
            $roots = [];

            foreach ($media as $mapping) {
                $entries = $this->content($mapping->liveDestination);

                if ($entries !== []) {
                    throw RestoreFailed::cleanHostRefused(sprintf('media root [%s] already holds %d entr%s; a clean-host restore needs absent or empty media roots', $mapping->name, count($entries), count($entries) === 1 ? 'y' : 'ies'));
                }

                $roots[$mapping->name] = $mapping->liveExists ? 'empty' : 'absent';
            }

            $evidence['media'] = $roots;
        }

        return $evidence;
    }

    /**
     * @return list<string> entries that count as existing content
     */
    private function content(string $path): array
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            return [$path];
        }

        if (! file_exists($path)) {
            return [];
        }

        if (! is_dir($path)) {
            return [$path];
        }

        $entries = @scandir($path);

        if ($entries === false) {
            throw RestoreFailed::cleanHostRefused('a media destination cannot be read');
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
            && ! (in_array($entry, self::PLACEHOLDERS, true) && is_file($path.'/'.$entry) && ! is_link($path.'/'.$entry))));
    }
}
