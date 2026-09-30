<?php

declare(strict_types=1);

namespace Quraba\Backup\Media;

/**
 * A validated media root: a stable logical name and its real absolute path.
 */
final readonly class MediaRoot
{
    public function __construct(
        public string $name,
        public string $path,
    ) {}

    /**
     * @return array{name: string, path: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'path' => $this->path];
    }
}
