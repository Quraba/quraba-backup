<?php

declare(strict_types=1);

namespace Quraba\Backup\Health;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
