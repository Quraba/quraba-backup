<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use InvalidArgumentException;

/**
 * Validation for Restic tags. Tags are package-controlled identity markers
 * (e.g. `app:{uuid}`, `env:production`, `run:{uuid}`); commas are refused
 * because Restic treats them as tag-list separators.
 */
final class ResticTag
{
    private const string PATTERN = '/^[a-z0-9][a-z0-9:_.\-]{0,127}$/';

    public static function assertValid(string $tag): string
    {
        if (preg_match(self::PATTERN, $tag) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Restic tag [%s]; tags are lowercase identifiers without commas or spaces.', mb_substr($tag, 0, 40)));
        }

        return $tag;
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function assertAll(array $tags): array
    {
        return array_map(self::assertValid(...), $tags);
    }
}
