<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Workspace\OperationWorkspace;
use Throwable;

/** Read-only capacity estimate plus harmless rename probes beside media roots. */
final readonly class RestorePreflight
{
    public function __construct(
        private Repository $config,
        private ResticRepository $repository,
        private ResticRunner $runner,
        private MediaRootResolver $roots,
    ) {}

    /** @return array{required_bytes: ?int, free_bytes: ?int, atomic_rename: ?bool, warnings: list<string>, blockers: list<string>} */
    public function check(RestoreSource $source, RestoreProfile $profile, OperationWorkspace $workspace): array
    {
        $warnings = [];
        $blockers = [];
        $estimate = 0;

        if ($profile !== RestoreProfile::Media) {
            if ($source->archiveBytes === null || $source->archiveBytes <= 0) {
                $blockers[] = 'The archive size is unknown, so staging capacity cannot be proven.';
            } else {
                // Downloaded encrypted ZIP plus a conservative extracted dump.
                $estimate += $source->archiveBytes * 3;
            }
        }

        if ($profile !== RestoreProfile::Database) {
            try {
                $inspection = $this->repository->inspect();
                if (! $inspection->state->isReady() || $inspection->repositoryId !== $source->repositoryId) {
                    throw new \RuntimeException('the configured repository ID differs from the source');
                }
                $stats = $this->runner->stats($source->snapshotId)->throwIfFailed()->json();
                $size = $stats['total_size'] ?? null;
                if (! is_int($size) || $size < 0) {
                    throw new \RuntimeException('Restic returned no reliable restore size');
                }
                $estimate += $size;
            } catch (Throwable) {
                $blockers[] = 'Media staging size could not be proven from the exact Restic snapshot.';
            }
        }

        $margin = $this->config->get('quraba-backup.restore.safety_margin_percent', 20);
        if (! is_int($margin) || $margin < 0 || $margin > 200) {
            $blockers[] = 'Restore safety_margin_percent must be an integer from 0 to 200.';
        } else {
            $estimate = (int) ceil($estimate * (100 + $margin) / 100);
        }

        $available = @disk_free_space($workspace->root());
        $free = is_float($available) ? (int) $available : null;
        if ($free === null) {
            $blockers[] = 'Free workspace disk space could not be measured.';
        } elseif ($estimate > $free) {
            $blockers[] = 'The private workspace has insufficient free disk space for reconstruction.';
        }

        $atomic = null;
        if ($profile !== RestoreProfile::Database) {
            $atomic = true;
            $snapshotRoots = array_column($source->mediaRoots, 'name');
            try {
                // Destinations need not exist yet (clean host); their parent must.
                foreach ($this->roots->destinations() as $destination) {
                    if (in_array($destination->name, $snapshotRoots, true) && ! $this->probeRename(dirname($destination->path))) {
                        $atomic = false;
                        break;
                    }
                }
            } catch (Throwable) {
                $atomic = false;
                $blockers[] = 'The configured media destinations are not valid.';
            }
            if (! $atomic) {
                if ((bool) $this->config->get('quraba-backup.restore.require_atomic_media_swap', true)) {
                    $blockers[] = 'A harmless rename probe beside a media destination failed; atomic replacement is required.';
                } else {
                    $warnings[] = 'Atomic media replacement could not be proven for a future live restore.';
                }
            }
        }

        return ['required_bytes' => $blockers === [] ? $estimate : ($estimate > 0 ? $estimate : null), 'free_bytes' => $free, 'atomic_rename' => $atomic, 'warnings' => $warnings, 'blockers' => $blockers];
    }

    private function probeRename(string $parent): bool
    {
        $suffix = bin2hex(random_bytes(12));
        $from = $parent.'/.quraba-rename-probe-'.$suffix;
        $to = $from.'-moved';
        if (! @mkdir($from, 0700)) {
            return false;
        }

        try {
            return @rename($from, $to) && is_dir($to);
        } finally {
            if (is_dir($to)) {
                @rmdir($to);
            }
            if (is_dir($from)) {
                @rmdir($from);
            }
        }
    }
}
