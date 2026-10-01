<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Closure;
use Quraba\Backup\Contracts\RestoreStepObserver;

/**
 * Records every restore step and runs a callback at chosen ones: throw to
 * simulate a failure or a dying process exactly there, or sabotage the
 * filesystem / the database to make the NEXT real operation fail.
 */
final class StepFaults implements RestoreStepObserver
{
    /** @var list<string> */
    public array $reached = [];

    /** @var array<string, Closure(array<string, scalar|null>): void> */
    private array $at = [];

    public function reached(string $step, array $context = []): void
    {
        $this->reached[] = $step;

        if (isset($this->at[$step])) {
            ($this->at[$step])($context);
        }
    }

    /**
     * @param  Closure(array<string, scalar|null>): void  $callback
     */
    public function at(string $step, Closure $callback): void
    {
        $this->at[$step] = $callback;
    }

    public function crashAt(string $step): void
    {
        $this->at($step, static function () use ($step): void {
            throw new SimulatedCrash('simulated failure at '.$step);
        });
    }

    public function clear(): void
    {
        $this->at = [];
        $this->reached = [];
    }
}
