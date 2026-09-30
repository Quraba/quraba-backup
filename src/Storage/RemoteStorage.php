<?php

declare(strict_types=1);

namespace Quraba\Backup\Storage;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Contracts\ObjectStorage;
use Quraba\Backup\Identity\IdentityResolver;

/**
 * Lazily resolves this application's remote layout and object storage, so
 * nothing contacts B2 or validates B2 settings until a backup needs it.
 */
final class RemoteStorage
{
    private ?RemoteLayout $layout = null;

    private ?ObjectStorage $objects = null;

    /**
     * @param  Closure(RemoteLayout): ObjectStorage  $factory
     */
    public function __construct(
        private readonly Repository $config,
        private readonly IdentityResolver $identity,
        private readonly Closure $factory,
    ) {}

    public function layout(): RemoteLayout
    {
        return $this->layout ??= RemoteLayout::fromConfig($this->config, $this->identity);
    }

    public function objects(): ObjectStorage
    {
        return $this->objects ??= ($this->factory)($this->layout());
    }
}
