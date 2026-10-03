<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/**
 * Detects the host platform. The raw values are injectable so the mapping
 * can be tested for every platform from any development machine.
 */
final readonly class PlatformDetector
{
    public function __construct(
        private ?string $osFamily = null,
        private ?string $machine = null,
    ) {}

    public function osFamily(): string
    {
        return $this->osFamily ?? PHP_OS_FAMILY;
    }

    /**
     * Shared hosts sometimes disable php_uname(). Use the platform's own
     * architecture report when available, otherwise refuse an unknown host.
     */
    public function machine(): string
    {
        if ($this->machine !== null) {
            return $this->machine;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (function_exists('php_uname') && ! in_array('php_uname', $disabled, true)) {
            return php_uname('m');
        }

        if ($this->osFamily() === 'Windows') {
            $architecture = getenv('PROCESSOR_ARCHITECTURE');

            return is_string($architecture) && $architecture !== '' ? $architecture : 'unknown';
        }

        $arch = @file_get_contents('/proc/sys/kernel/arch');

        return is_string($arch) && trim($arch) !== '' ? trim($arch) : 'unknown';
    }

    public function detect(): ResticPlatform
    {
        return ResticPlatform::fromHost($this->osFamily(), $this->machine());
    }
}
