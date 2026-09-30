<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Safe operational settings only (schedules, retention values, thresholds).
 *
 * Secrets never belong here: keys that look like credentials are refused and
 * values must be plain scalars or lists/maps of scalars. Filesystem paths are
 * deployment configuration and are not stored here either.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BackupSetting extends PackageModel
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){0,5}$/';

    private const string FORBIDDEN_KEY_PATTERN = '/(password|passphrase|secret|token|credential|key_id|application_key|api_key|private_key|access_key|app_key|path|binary|endpoint|repository)/';

    protected $table = 'quraba_backup_settings';

    /** @var list<string> */
    protected $fillable = [];

    public static function put(string $key, mixed $value): self
    {
        self::assertSafeKey($key);
        self::assertSafeValue($value);

        $setting = self::query()->where('key', $key)->first() ?? new self;
        $setting->setAttribute('key', $key);
        $setting->setAttribute('value', ['v' => $value]);
        $setting->save();

        return $setting;
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        self::assertSafeKey($key);

        $setting = self::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        $stored = $setting->getAttribute('value');

        return is_array($stored) && array_key_exists('v', $stored) ? $stored['v'] : $default;
    }

    public static function assertSafeKey(string $key): void
    {
        if (strlen($key) > 191 || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Setting keys must be dotted snake_case identifiers.');
        }

        if (preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1) {
            throw new InvalidArgumentException(sprintf('The setting [%s] looks like a secret or deployment path; those belong in environment configuration, never in the database.', $key));
        }
    }

    private static function assertSafeValue(mixed $value, int $depth = 0): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return;
        }

        if (is_string($value)) {
            if (mb_strlen($value) > 1000) {
                throw new InvalidArgumentException('Setting string values are limited to 1000 characters.');
            }

            return;
        }

        if (is_array($value) && $depth < 3) {
            foreach ($value as $item) {
                self::assertSafeValue($item, $depth + 1);
            }

            return;
        }

        throw new InvalidArgumentException('Setting values must be scalars or shallow arrays of scalars.');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'value' => 'array',
        ];
    }
}
