<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Restore\RestoreDryRunService;
use Throwable;

final class RestoreCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:restore
        {--run= : Exact source run UUID}
        {--profile=full : database, media or full}
        {--json : Output machine-readable JSON}
        {--force : Unsupported; live execution is not implemented}';

    protected $description = 'Reconstruct and validate one exact backup in a private workspace (dry run only).';

    public function handle(RestoreDryRunService $restore): int
    {
        if ($this->option('force')) {
            return $this->failWith(RestoreFailed::liveNotImplemented());
        }
        $profileValue = $this->option('profile');
        $profile = is_string($profileValue) ? RestoreProfile::tryFrom($profileValue) : null;
        $runUuid = $this->option('run');
        if ($profile === null || ! is_string($runUuid) || $runUuid === '') {
            return $this->failWith(new \InvalidArgumentException('Specify --run=UUID and --profile=database|media|full.'));
        }

        try {
            $report = $restore->run($runUuid, $profile);
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson($report);
        } else {
            foreach ($report as $key => $value) {
                if (in_array($key, ['ok', 'notice', 'error'], true)) {
                    continue;
                }
                $display = is_array($value) ? (string) json_encode($this->redactor()->redactArray($value))
                    : ($value === null ? 'unknown' : (is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $this->redactor()->redact((string) $value) : 'unknown')));
                $this->components->twoColumnDetail(str_replace('_', ' ', (string) $key), $display);
            }
            if (! $report['ok']) {
                $this->components->error('Restore dry run failed; review blockers.');
            }
            $this->line('Nothing was changed in the live application.');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
