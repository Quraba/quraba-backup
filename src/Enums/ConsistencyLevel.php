<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

/**
 * How strongly the components of one run describe the same application state.
 *
 * `Quiesced` may only be recorded when a quiescence provider has proven it.
 */
enum ConsistencyLevel: string
{
    case None = 'none';
    case BestEffort = 'best_effort';
    case Quiesced = 'quiesced';
}
