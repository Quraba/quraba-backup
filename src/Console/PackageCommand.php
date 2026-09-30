<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Illuminate\Console\Command;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Health\CheckStatus;
use Quraba\Backup\Health\HealthReport;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * Shared output helpers. Every message that may contain external text goes
 * through the redactor before it is printed.
 */
abstract class PackageCommand extends Command
{
    protected function redactor(): SecretRedactor
    {
        return $this->laravel->make(SecretRedactor::class);
    }

    protected function failWith(Throwable $exception): int
    {
        $code = $exception instanceof QurabaBackupException ? $exception->failureCode() : 'unexpected.error';
        $message = $this->redactor()->redact($exception->getMessage());

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
        } else {
            $this->components->error(sprintf('[%s] %s', $code, $message));
        }

        return self::FAILURE;
    }

    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    protected function writeJson(array $payload): void
    {
        $this->line((string) json_encode(
            $this->redactor()->redactArray($payload),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    protected function renderReport(HealthReport $report, string $title): void
    {
        $this->newLine();
        $this->line(sprintf('  <options=bold>%s</>  <fg=gray>%s</>', $title, $report->checkedAt->toIso8601ZuluString()));
        $this->newLine();

        foreach ($report->checks as $check) {
            $badge = match ($check->status) {
                CheckStatus::Pass => '<fg=green;options=bold>PASS</>',
                CheckStatus::Warn => '<fg=yellow;options=bold>WARN</>',
                CheckStatus::Fail => '<fg=red;options=bold>FAIL</>',
                CheckStatus::Skip => '<fg=gray;options=bold>SKIP</>',
            };

            $this->line(sprintf('  %s  %-32s %s', $badge, $check->label, $this->escape($this->redactor()->redact($check->message))));
        }

        $counts = $report->counts();
        $this->newLine();
        $this->line(sprintf(
            '  Overall: <options=bold>%s</>  (%d pass, %d warn, %d fail, %d skip)',
            strtoupper($report->state()->value),
            $counts['pass'],
            $counts['warn'],
            $counts['fail'],
            $counts['skip'],
        ));
        $this->newLine();
    }

    protected function escape(string $text): string
    {
        return str_replace(['<', '>'], ['\\<', '\\>'], $text);
    }
}
