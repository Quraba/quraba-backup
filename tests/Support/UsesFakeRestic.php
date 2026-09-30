<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Health\ResticHealthService;
use Quraba\Backup\Restic\Installer\ResticInstaller;
use Quraba\Backup\Restic\RepositoryContextResolver;
use Quraba\Backup\Restic\ResticBinaryResolver;
use Quraba\Backup\Restic\ResticConfig;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Tests\TestCase;
use Symfony\Component\Uid\Ulid;

/**
 * @mixin TestCase
 */
trait UsesFakeRestic
{
    protected ScriptAwareProcessFactory $processes;

    protected string $fakeRestic;

    /**
     * Installs the fake Restic as the explicitly configured binary.
     *
     * @param  array<string, mixed>  $scenario
     */
    protected function useFakeRestic(array $scenario = [], ?string $at = null): string
    {
        $path = $at ?? $this->sandbox.'/fake-'.(string) new Ulid.'/restic';

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        copy(dirname(__DIR__).'/Fixtures/fake-restic.php', $path);
        chmod($path, 0700);
        $this->writeScenario($scenario, dirname($path));

        if ($at === null) {
            $this->config()->set('restic.binary', $path);
        }

        $this->fakeRestic = $path;
        $this->refreshPackageServices();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    protected function writeScenario(array $scenario, ?string $directory = null): void
    {
        $directory ??= dirname($this->fakeRestic);
        file_put_contents($directory.'/fake-restic.json', (string) json_encode($scenario));
        @unlink($directory.'/repo-state');
    }

    /**
     * @return list<array{argv: list<string>, env: array<string, string>, cwd: string}>
     */
    protected function invocations(?string $binary = null): array
    {
        $file = dirname($binary ?? $this->fakeRestic).'/invocations.jsonl';

        if (! is_file($file)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($file)));

        /** @var list<array{argv: list<string>, env: array<string, string>, cwd: string}> */
        return array_values(array_map(static fn (string $line): array => json_decode($line, true), $lines));
    }

    /**
     * @return list<string>
     */
    protected function invokedCommands(?string $binary = null): array
    {
        return array_map(static fn (array $invocation): string => implode(' ', $invocation['argv']), $this->invocations($binary));
    }

    protected function refreshPackageServices(): void
    {
        $this->processes ??= new ScriptAwareProcessFactory;

        foreach ([
            ResticConfig::class, ResticRunner::class, ResticBinaryResolver::class, RepositoryContextResolver::class,
            ResticRepository::class, ResticInstaller::class, ResticHealthService::class, PackagePaths::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        $this->app->instance(ProcessFactory::class, $this->processes);
    }
}
