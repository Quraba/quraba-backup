<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

final readonly class DatabaseTool
{
    public function __construct(
        public DatabaseToolKind $kind,
        public string $name,
        public string $path,
        public string $version,
        public bool $configured,
    ) {}

    /**
     * @return array{kind: string, name: string, path: string, version: string, configured: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'path' => $this->path,
            'version' => $this->version,
            'configured' => $this->configured,
        ];
    }
}
