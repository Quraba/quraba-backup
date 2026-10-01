<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Throwable;

final class RestoreReconcileCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:restore-reconcile
        {--restore= : Restore UUID to reconcile; without it the restore journals are only listed}
        {--abandon : Close an INDETERMINATE restore without proof of its final state (also requires --confirm)}
        {--confirm= : The exact phrase ABANDON_RESTORE}
        {--cleanup-parked : Remove the parked pre-restore media of a COMPLETED restore}
        {--json : Output machine-readable JSON}';

    protected $description = 'Inspect restore journals and decide the outcome of an interrupted live restore from physical evidence (never repeats SQL, never rolls back).';

    public function handle(RestoreReconciler $reconciler): int
    {
        $restore = $this->option('restore');

        try {
            if (! is_string($restore) || $restore === '') {
                return $this->overview($reconciler);
            }

            $abandon = (bool) $this->option('abandon');

            if ($abandon && $this->option('confirm') !== RestoreReconciler::ABANDON_PHRASE) {
                throw RestoreFailed::confirmationRequired(sprintf('abandoning a restore needs --abandon --confirm=%s; it declares that you take responsibility for the application state', RestoreReconciler::ABANDON_PHRASE));
            }

            $report = $reconciler->reconcile($restore, $abandon, (bool) $this->option('cleanup-parked'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson($report);
        } else {
            $this->components->twoColumnDetail('restore', $restore);
            $this->components->twoColumnDetail('outcome', strtoupper(is_string($report['outcome']) ? $report['outcome'] : ''));
            $this->components->twoColumnDetail('catalog audit row', is_string($report['audit']) ? $this->escape($report['audit']) : '');
            $this->components->twoColumnDetail('safety backup protection', is_string($report['safety_backup_pin']) ? $report['safety_backup_pin'] : '');
            $this->components->twoColumnDetail('evidence', $this->escape((string) json_encode($this->redactor()->redactArray(is_array($report['evidence']) ? $report['evidence'] : []), JSON_UNESCAPED_SLASHES)));

            foreach (is_array($report['parked_removed']) ? $report['parked_removed'] : [] as $item) {
                if (is_array($item)) {
                    $this->components->twoColumnDetail('parked '.(is_string($item['root'] ?? null) ? $item['root'] : ''), is_string($item['result'] ?? null) ? $item['result'] : '');
                }
            }

            foreach (is_array($report['guidance']) ? $report['guidance'] : [] as $line) {
                $this->line('  • '.$this->escape(is_string($line) ? $line : ''));
            }

            $this->line(is_string($report['notice']) ? $report['notice'] : '');
        }

        return $report['ok'] === true ? self::SUCCESS : RestoreCommand::EXIT_INDETERMINATE;
    }

    private function overview(RestoreReconciler $reconciler): int
    {
        $overview = $reconciler->overview();

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => $overview['unresolved'] === 0 && $overview['unreadable'] === [], ...$overview]);
        } else {
            $this->table(['Restore UUID', 'Profile', 'Source run', 'Safety run', 'Phase', 'Boundary crossed', 'State'], array_map(static fn (array $journal): array => [
                $journal['restore_uuid'],
                $journal['profile'],
                $journal['source_run_uuid'],
                $journal['safety_backup_run_uuid'] ?? '-',
                $journal['phase'],
                $journal['destructive_started_at'] === null ? 'no' : 'YES',
                $journal['unresolved'] === true ? 'UNRESOLVED' : ($journal['resolution'] ?? $journal['terminal']),
            ], $overview['journals']));

            foreach ($overview['unreadable'] as $file) {
                $this->components->error('Unreadable restore journal: '.$overview['directory'].'/'.$file);
            }

            if ($overview['unresolved'] > 0) {
                $this->components->warn(sprintf('%d restore(s) are unresolved and block further live restores. Reconcile each with --restore=UUID.', $overview['unresolved']));
            }
        }

        return $overview['unresolved'] === 0 && $overview['unreadable'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
