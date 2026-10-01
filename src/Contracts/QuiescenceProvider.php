<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Exceptions\QuiescenceFailed;

/**
 * Brings the application into a state in which a Recovery Point's database
 * and media can be captured, and reports truthfully what it can prove.
 *
 * A provider may only claim `quiesced` when it can establish that NO writer
 * (web, queue workers, scheduler, external consumers, CLI scripts) can change
 * application state during the capture.
 */
interface QuiescenceProvider
{
    public function name(): string;

    /**
     * Whether, as configured, {@see self::enter()} can yield a session that
     * PROVES quiescence. A live restore refuses up front when this is false;
     * it still requires the entered session itself to be `quiesced`.
     */
    public function claimsQuiescence(): bool;

    /**
     * @throws QuiescenceFailed
     */
    public function enter(): QuiescenceSession;
}
