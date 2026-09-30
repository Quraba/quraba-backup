<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticRepository;
use Throwable;

final class ResticInitCommand extends PackageCommand
{
    protected $signature = 'backup:restic:init
        {--force : Skip the interactive confirmation (the repository must still be confirmed absent)}';

    protected $description = 'Explicitly initialize the Restic repository after confirming it does not exist yet.';

    public function handle(): int
    {
        try {
            $repository = $this->laravel->make(ResticRepository::class);
            $inspection = $repository->inspect();
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        $this->components->twoColumnDetail('Repository', (string) ($inspection->location ?? 'not configured'));
        $this->components->twoColumnDetail('Current state', $inspection->state->value);

        if ($inspection->state === RepositoryState::Ready) {
            $this->components->warn(sprintf('A repository already exists here (id %s). Nothing was changed.', $inspection->repositoryId));

            return self::FAILURE;
        }

        if ($inspection->state !== RepositoryState::Uninitialized) {
            $this->components->error('Refusing to initialize: the repository absence is not confirmed. '.$this->escape($this->redactor()->redact($inspection->message)));
            $this->line('  Fix the configuration, credentials, password file or connectivity first. A repository is only created when Restic itself reports that none exists.');

            return self::FAILURE;
        }

        $this->line('  Restic reports that no repository exists at this location.');
        $this->line('  If the location has a typo, initializing would create a second, empty repository and leave the real one unused.');

        if (! (bool) $this->option('force')) {
            if (! $this->input->isInteractive()) {
                return $this->failWith(new ConfigurationException('Non-interactive initialization requires --force.', 'restic.init_confirmation_required'));
            }

            if (! $this->confirm('Initialize a NEW, EMPTY Restic repository at the location above?', false)) {
                $this->components->info('Aborted. Nothing was changed.');

                return self::FAILURE;
            }
        }

        try {
            $result = $repository->initialize();
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        $this->components->info(sprintf('Repository initialized (id %s, format v%d) and read back successfully.', $result->repositoryId, (int) $result->formatVersion));
        $this->line('  Store the Restic password file contents outside this server now. Without it the repository can never be opened.');

        return self::SUCCESS;
    }
}
