<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Recovery\EnvBootstrapper;
use Throwable;

final class BootstrapEnvCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:bootstrap-env
        {--run= : Exact run UUID whose archived .env is recovered}
        {--target= : Absolute path of the file to write (default: .env.recovered in the application root)}
        {--overwrite : Replace an existing target (also requires --confirm)}
        {--confirm= : The exact phrase OVERWRITE_ENV}
        {--json : Output machine-readable JSON}';

    protected $description = 'Clean host: recover the archived .env of one exact run from remote storage, without restoring the application.';

    public function handle(EnvBootstrapper $bootstrapper): int
    {
        $run = $this->option('run');
        $target = $this->option('target');
        $overwrite = (bool) $this->option('overwrite');

        try {
            if (! is_string($run) || $run === '') {
                throw new \InvalidArgumentException('Specify --run=UUID (see "php artisan quraba:backup:discover --remote").');
            }

            if ($overwrite && $this->option('confirm') !== EnvBootstrapper::OVERWRITE_PHRASE) {
                throw RestoreFailed::confirmationRequired(sprintf('replacing an existing file needs --overwrite --confirm=%s', EnvBootstrapper::OVERWRITE_PHRASE));
            }

            // Never the live .env by default.
            $result = $bootstrapper->bootstrap($run, is_string($target) && $target !== '' ? $target : $this->laravel->basePath('.env.recovered'), $overwrite);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => true, ...$result]);
        } else {
            $this->components->info(sprintf('The archived .env of run %s was written to %s (%d bytes, private file).', $result['run_uuid'], $result['target'], $result['bytes']));
            $this->line('Its content was not displayed. Review it, move it to .env yourself, then reload the configuration (php artisan config:clear).');
        }

        return self::SUCCESS;
    }
}
