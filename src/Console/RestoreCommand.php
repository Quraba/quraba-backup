<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Restore\RestoreDryRunService;
use Throwable;

/**
 * Dry run by default. A LIVE restore needs BOTH `--force` and
 * `--confirm=<exact phrase>`; a question answered with "yes" is never
 * enough, and the live services are not even resolved for a dry run.
 */
final class RestoreCommand extends PackageCommand
{
    public const int EXIT_INDETERMINATE = 3;

    protected $signature = 'quraba:backup:restore
        {--run= : Exact source run UUID}
        {--profile=full : database, media or full}
        {--force : LIVE restore: replace application data (also requires --confirm)}
        {--confirm= : LIVE restore: the exact confirmation phrase}
        {--clean-host : LIVE restore onto a new, proven-empty host (no safety backup is possible or needed)}
        {--json : Output machine-readable JSON}';

    protected $description = 'Dry run (default): reconstruct and validate one exact backup privately. With --force --confirm=PHRASE: replace the live application with it.';

    public function handle(): int
    {
        $profileValue = $this->option('profile');
        $profile = is_string($profileValue) ? RestoreProfile::tryFrom($profileValue) : null;
        $runUuid = $this->option('run');
        if ($profile === null || ! is_string($runUuid) || $runUuid === '') {
            return $this->failWith(new \InvalidArgumentException('Specify --run=UUID and --profile=database|media|full.'));
        }

        $live = (bool) $this->option('force') || $this->option('confirm') !== null || (bool) $this->option('clean-host');

        return $live ? $this->live($runUuid, $profile) : $this->dryRun($runUuid, $profile);
    }

    private function dryRun(string $runUuid, RestoreProfile $profile): int
    {
        try {
            $report = $this->laravel->make(RestoreDryRunService::class)->run($runUuid, $profile);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson($report);
        } else {
            $this->details($report, ['ok', 'notice', 'error']);
            if (! $report['ok']) {
                $this->components->error('Restore dry run failed; review blockers.');
            }
            $this->line('Nothing was changed in the live application.');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function live(string $runUuid, RestoreProfile $profile): int
    {
        $config = $this->laravel->make(Repository::class);
        $cleanHost = (bool) $this->option('clean-host');

        try {
            // Never an implicit live restore: both gates, exactly.
            $authorization = LiveRestoreAuthorization::confirm($config, (bool) $this->option('force'), $this->option('confirm'), $cleanHost);
        } catch (RestoreFailed $refusal) {
            return $this->refuseUnconfirmed($refusal, $runUuid, $profile, $cleanHost);
        }

        $service = $this->laravel->make(LiveRestoreService::class);

        try {
            if (! $this->wantsJson()) {
                $this->components->warn('LIVE RESTORE — this replaces application data.');
                $this->details($service->describe($runUuid, $profile, $cleanHost), []);
            }

            $report = $service->run($runUuid, $profile, $authorization);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson($report);
        } else {
            $this->newLine();
            $this->details($report, ['ok', 'notice', 'error', 'follow_up', 'mode']);
            $error = is_array($report['error'] ?? null) ? $report['error'] : null;

            if ($error !== null) {
                $this->components->error(sprintf('[%s] %s', is_string($error['code'] ?? null) ? $error['code'] : 'restore.failed', $this->redactor()->redact(is_string($error['message'] ?? null) ? $error['message'] : '')));
            }

            $this->line(is_string($report['notice']) ? $report['notice'] : '');

            foreach (is_array($report['follow_up']) ? $report['follow_up'] : [] as $step) {
                $this->line('  → '.(is_string($step) ? $step : ''));
            }
        }

        return match ($report['status']) {
            'completed' => self::SUCCESS,
            'indeterminate' => self::EXIT_INDETERMINATE,
            default => self::FAILURE,
        };
    }

    /**
     * Shows what a live restore would replace and how to confirm it. Changes nothing.
     */
    private function refuseUnconfirmed(RestoreFailed $refusal, string $runUuid, RestoreProfile $profile, bool $cleanHost): int
    {
        if ($this->wantsJson()) {
            return $this->failWith($refusal);
        }

        try {
            $this->components->warn('A LIVE restore of this run would replace:');
            $this->details($this->laravel->make(LiveRestoreService::class)->describe($runUuid, $profile, $cleanHost), []);
        } catch (Throwable $exception) {
            $this->components->warn('The restore target could not be described: '.$this->redactor()->redact($exception->getMessage()));
        }

        $this->failWith($refusal);
        $this->line('Nothing was changed in the live application.');

        return self::FAILURE;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $skip
     */
    private function details(array $values, array $skip): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $display = is_array($value) ? (string) json_encode($this->redactor()->redactArray($value), JSON_UNESCAPED_SLASHES)
                : ($value === null ? 'unknown' : (is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $this->redactor()->redact((string) $value) : 'unknown')));
            $this->components->twoColumnDetail(str_replace('_', ' ', (string) $key), $this->escape($display));
        }
    }
}
