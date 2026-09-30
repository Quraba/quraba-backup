<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/**
 * The closed set of Restic operations the package can execute.
 */
enum ResticOperation: string
{
    case Version = 'version';
    case Init = 'init';
    case CatConfig = 'cat_config';
    case Snapshots = 'snapshots';
    case ListLocks = 'list_locks';
    case Stats = 'stats';
    case Backup = 'backup';
    case Restore = 'restore';
    case Check = 'check';
    case Forget = 'forget';
    case Prune = 'prune';

    public function timeoutClass(): string
    {
        return match ($this) {
            self::Version => 'version',
            self::Init => 'init',
            self::CatConfig, self::Snapshots, self::ListLocks, self::Stats => 'query',
            self::Backup => 'backup',
            self::Restore => 'restore',
            self::Check => 'check',
            self::Forget => 'forget',
            self::Prune => 'prune',
        };
    }

    public function needsRepository(): bool
    {
        return $this !== self::Version;
    }

    /**
     * Operations that change repository contents.
     */
    public function isWrite(): bool
    {
        return in_array($this, [self::Init, self::Backup, self::Forget, self::Prune], true);
    }

    public function label(): string
    {
        return 'restic '.str_replace('_', ' ', $this->value);
    }
}
