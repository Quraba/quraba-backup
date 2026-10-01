<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Recovery\RecoveryChecklist;
use Throwable;

final class RecoveryChecklistCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:recovery-checklist
        {--remote : Also read the repository identity from the remote manifests}
        {--json : Output machine-readable JSON}';

    protected $description = 'List the external recovery materials a clean-host restore needs and whether each is configured (never prints a secret value).';

    public function handle(RecoveryChecklist $checklist): int
    {
        try {
            $result = $checklist->evaluate((bool) $this->option('remote'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $result['ready'], ...$result]);
        } else {
            foreach ($result['items'] as $item) {
                $badge = $item['state'] === 'configured' ? '<fg=green;options=bold>CONFIGURED</>' : ($item['required'] ? '<fg=red;options=bold>MISSING   </>' : '<fg=yellow;options=bold>MISSING   </>');
                $this->line(sprintf('  %s  %-44s %s', $badge, $item['key'], $item['label']));
                $this->line('              <fg=gray>'.$this->escape($item['guidance']).'</>');
            }

            $this->newLine();
            $this->line(sprintf('  Restic repository ID: %s <fg=gray>(%s)</>', $result['repository_id'] ?? 'unknown', $result['repository_id_source']));
            $this->line('  Keep a copy of every value outside this server. This command never displays a secret.');
            $this->newLine();
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
