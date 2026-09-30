<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

enum InstallOutcome: string
{
    case Installed = 'installed';
    case Replaced = 'replaced';
    case AlreadyInstalled = 'already_installed';
}
