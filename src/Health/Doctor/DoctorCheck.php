<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor;

use Quraba\Backup\Health\CheckResult;

/**
 * A group of related doctor checks. Checks must not change the environment
 * beyond temporary probes they remove again, must never install anything,
 * and must never output secrets.
 */
interface DoctorCheck
{
    public function name(): string;

    /**
     * @return list<CheckResult>
     */
    public function run(): array;
}
