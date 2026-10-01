<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Quraba\Backup\Recovery\CatalogRebuilder;
use Throwable;

final class CatalogRebuildCommand extends PackageCommand
{
    protected $signature = 'quraba:backup:catalog:rebuild
        {--apply : Adopt runs into the local catalog (default: plan only)}
        {--json : Output machine-readable JSON}';

    protected $description = 'Rebuild the local backup catalog from remote manifests, expiry records and physical evidence (plan only unless --apply).';

    public function handle(CatalogRebuilder $rebuilder): int
    {
        try {
            $report = $rebuilder->run((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            return $this->failWith($exception);
        }

        if ($this->wantsJson()) {
            $this->writeJson($report);
        } else {
            $rows = [];

            foreach (is_array($report['runs']) ? $report['runs'] : [] as $run) {
                if (is_array($run)) {
                    $components = is_array($run['components'] ?? null) ? $run['components'] : [];
                    $rows[] = [
                        $run['run_uuid'] ?? '',
                        $run['created_at'] ?? '',
                        $run['profile'] ?? '',
                        $components['application_archive'] ?? '-',
                        $components['media_snapshot'] ?? '-',
                        $run['local_status'] ?? 'absent',
                        $run['action'] ?? '',
                    ];
                }
            }

            $this->table(['Run UUID', 'Created UTC', 'Profile', 'Archive', 'Snapshot', 'Local', 'Action'], $rows);

            if (! $report['catalog_available']) {
                $this->components->warn('The catalog tables do not exist on this host; run "php artisan migrate" before --apply.');
            }

            $error = is_array($report['error']) ? $report['error'] : null;

            if ($error !== null) {
                $this->components->error(sprintf('[%s] %s', is_string($error['code'] ?? null) ? $error['code'] : '', is_string($error['message'] ?? null) ? $error['message'] : ''));
            }

            $this->line(is_string($report['notice']) ? $report['notice'] : '');
        }

        return $report['ok'] === true ? self::SUCCESS : self::FAILURE;
    }
}
