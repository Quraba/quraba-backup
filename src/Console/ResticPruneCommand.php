<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Maintenance\ResticMaintenanceService;
use Throwable;

final class ResticPruneCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:restic:prune
        {--execute : Repack and remove unreferenced data}
        {--json : Output machine-readable JSON}';

    protected $description = 'Audit a Restic prune dry run; --execute performs the prune.';

    public function handle(ResticMaintenanceService $maintenance): int
    {
        try {
            $result = $maintenance->prune((bool) $this->option('execute'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $result->succeeded(), ...$result->toArray()]);
        } else {
            $this->line(sprintf('Restic prune %s: %s (maintenance %s)', $result->mode, $result->status->value, $result->maintenanceRunUuid));
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
