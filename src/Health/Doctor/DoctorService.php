<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor;

use Carbon\CarbonImmutable;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\HealthReport;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * Runs every doctor check group. A crashing check becomes a FAIL entry with
 * a redacted message; the doctor itself never aborts halfway.
 */
final readonly class DoctorService
{
    /**
     * @param  iterable<DoctorCheck>  $checks
     */
    public function __construct(
        private iterable $checks,
        private SecretRedactor $redactor,
    ) {}

    public function run(): HealthReport
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                foreach ($check->run() as $result) {
                    $results[] = $this->sanitize($result);
                }
            } catch (Throwable $exception) {
                $results[] = CheckResult::fail(
                    $check->name().'.error',
                    ucfirst($check->name()).' checks',
                    'The check crashed: '.$this->redactor->redact($exception->getMessage()),
                );
            }
        }

        return new HealthReport($results, CarbonImmutable::now('UTC'));
    }

    private function sanitize(CheckResult $result): CheckResult
    {
        return new CheckResult(
            $result->id,
            $result->label,
            $result->status,
            $this->redactor->redact($result->message),
            $this->redactor->redactArray($result->details),
            $result->impact,
        );
    }
}
