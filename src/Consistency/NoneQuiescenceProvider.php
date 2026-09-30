<?php

declare(strict_types=1);

namespace Quraba\Backup\Consistency;

use Quraba\Backup\Contracts\QuiescenceProvider;

/**
 * Does not stop writers: a Recovery Point captured with it is `best_effort`.
 */
final class NoneQuiescenceProvider implements QuiescenceProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function enter(): QuiescenceSession
    {
        return QuiescenceSession::none($this->name(), 'Application writes may continue while the database and media are captured.');
    }
}
