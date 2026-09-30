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
     * Shared hosts often disable php_uname(); fall back to the kernel's
     * architecture file, and report "unknown" (which is refused) otherwise.
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

        $arch = @file_get_contents('/proc/sys/kernel/arch');

        return is_string($arch) && trim($arch) !== '' ? trim($arch) : 'unknown';
    }

    public function detect(): ResticPlatform
    {
        return ResticPlatform::fromHost($this->osFamily(), $this->machine());
    }
}
