<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Maintenance\ResticMaintenanceService;
use Throwable;

final class ResticCheckCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:restic:check
        {--read-data : Read every repository pack (may incur substantial download traffic)}
        {--scheduled : Invoked by Laravel scheduler}
        {--json : Output machine-readable JSON}';

    protected $description = 'Run and audit a real Restic repository integrity check.';

    public function handle(ResticMaintenanceService $maintenance): int
    {
        try {
            $result = $maintenance->check((bool) $this->option('read-data'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $result->succeeded(), ...$result->toArray()]);
        } else {
            $this->line(sprintf('Restic check %s (maintenance %s)', $result->status->value, $result->maintenanceRunUuid));
            foreach ($result->summary as $line) {
                $this->line($this->escape($this->redactor()->redact($line)));
            }
            if ($result->failure !== null) {
                $this->components->error('['.$result->failure->code.'] '.$result->failure->message);
            }
        }

        return $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
