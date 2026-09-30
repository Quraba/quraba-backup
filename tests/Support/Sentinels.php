<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Recognizable fake secrets. Tests assert that none of them ever survives
 * into output, logs, exceptions or persisted failure messages.
 */
final class Sentinels
{
    public const string B2_KEY_ID = 'SENTINELKEYID0045f7a3c9e21';

    public const string B2_SECRET = 'K004SENTINEL/secret+Value=b2c9d41e';

    public const string RESTIC_PASSWORD = 'sentinel-restic-password-7f3a9b1c';

    public const string ARCHIVE_PASSWORD = 'sentinel-archive-password-51e0aa';

    public const string DB_PASSWORD = 'sentinel-db-password-2b7c90';

    public const string APP_KEY = 'base64:U0VOVElORUwtQVBQLUtFWS0xMjM0NTY3ODkwYWI=';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::B2_KEY_ID, self::B2_SECRET, self::RESTIC_PASSWORD, self::ARCHIVE_PASSWORD, self::DB_PASSWORD, self::APP_KEY];
    }

    public static function assertAbsent(string $text, string $context = ''): void
    {
        foreach (self::all() as $secret) {
            foreach ([$secret, rawurlencode($secret), urlencode($secret), substr((string) json_encode($secret), 1, -1)] as $variant) {
                Assert::assertStringNotContainsString($variant, $text, sprintf('A sentinel secret leaked%s.', $context === '' ? '' : ' into '.$context));
            }
        }
    }
}
