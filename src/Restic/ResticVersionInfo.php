<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Exceptions\ResticCommandFailed;

/**
 * Parsed output of `restic version --json`.
 */
final readonly class ResticVersionInfo
{
    public function __construct(
        public string $version,
        public ?string $goVersion,
        public ?string $goOs,
        public ?string $goArch,
    ) {}

    /**
     * @param  array<array-key, mixed>  $message
     */
    public static function fromJson(array $message): self
    {
        $version = $message['version'] ?? null;

        if (($message['message_type'] ?? null) !== 'version' || ! is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new ResticCommandFailed('The binary did not return a valid `restic version --json` document.');
        }

        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self($version, $string($message['go_version'] ?? null), $string($message['go_os'] ?? null), $string($message['go_arch'] ?? null));
    }

    public function matches(string $pinnedVersion): bool
    {
        return $this->version === $pinnedVersion;
    }

    /**
     * @return array{version: string, go_version: string|null, go_os: string|null, go_arch: string|null}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'go_version' => $this->goVersion,
            'go_os' => $this->goOs,
            'go_arch' => $this->goArch,
        ];
    }
}
