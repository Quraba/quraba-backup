<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Resolves a check group only when it runs, so that a construction failure
 * (for example invalid configuration) is reported as a FAIL for that group.
 *
 * @internal
 */
final readonly class LazyDoctorCheck implements DoctorCheck
{
    /**
     * @param  class-string<DoctorCheck>  $class
     */
    public function __construct(
        private Container $container,
        private string $class,
    ) {}

    public function name(): string
    {
        $short = substr($this->class, (int) strrpos($this->class, '\\') + 1);

        return strtolower((string) preg_replace('/Checks?$/', '', $short));
    }

    public function run(): array
    {
        $check = $this->container->make($this->class);

        if (! $check instanceof DoctorCheck) {
            throw new LogicException(sprintf('%s is not a doctor check.', $this->class));
        }

        return $check->run();
    }
}
