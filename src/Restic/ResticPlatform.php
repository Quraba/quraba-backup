<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Exceptions\EnvironmentUnsupported;

/**
 * An OS/architecture pair in Restic's release naming (e.g. linux/amd64).
 */
final readonly class ResticPlatform
{
    /** Platforms the installer knows how to handle. */
    private const array SUPPORTED = [
        'linux' => ['amd64', 'arm64'],
        'windows' => ['amd64'],
    ];

    private function __construct(
        public string $os,
        public string $arch,
    ) {}

    public static function of(string $os, string $arch): self
    {
        if (! in_array($arch, self::SUPPORTED[$os] ?? [], true)) {
            throw new EnvironmentUnsupported(sprintf(
                'Restic installation is not supported on %s/%s. Supported: linux/amd64, linux/arm64 and windows/amd64. Install a verified Restic %s binary manually and set QURABA_BACKUP_RESTIC_BINARY if this host must be used.',
                $os,
                $arch,
                ResticRelease::VERSION,
            ));
        }

        return new self($os, $arch);
    }

    /**
     * Maps PHP's view of the host to Restic's release naming.
     */
    public static function fromHost(string $phpOsFamily, string $machine): self
    {
        $os = match (strtolower($phpOsFamily)) {
            'linux' => 'linux',
            'darwin' => 'darwin',
            'bsd' => 'freebsd',
            'windows' => 'windows',
            default => strtolower($phpOsFamily),
        };

        $arch = match (strtolower($machine)) {
            'x86_64', 'amd64', 'x64' => 'amd64',
            'aarch64', 'arm64', 'armv8', 'armv8l' => 'arm64',
            'i386', 'i686', 'x86' => '386',
            default => strtolower($machine),
        };

        return self::of($os, $arch);
    }

    public function key(): string
    {
        return $this->os.'_'.$this->arch;
    }

    public function label(): string
    {
        return $this->os.'/'.$this->arch;
    }
}
