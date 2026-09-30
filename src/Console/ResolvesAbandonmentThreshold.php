<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;

/**
 * @mixin PackageCommand
 */
trait ResolvesAbandonmentThreshold
{
    /**
     * Hours after which an inactive workspace is abandoned. At least one hour,
     * so cleanup never races a workspace that is still being set up.
     */
    private function abandonmentHours(): int
    {
        $option = $this->option('older-than');
        $value = $option === null
            ? $this->laravel->make(Repository::class)->get('quraba-backup.workspace.abandoned_after_hours', 24)
            : $option;

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new ConfigurationException('The abandonment threshold must be a whole number of hours, at least 1.');
        }

        return $value;
    }
}
