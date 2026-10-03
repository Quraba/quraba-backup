<?php

declare(strict_types=1);

namespace Quraba\Backup\Support\Process;

/**
 * Builds a minimal environment for child processes.
 *
 * Symfony Process inherits the parent environment by default. In a Laravel
 * application that environment contains everything loaded from .env
 * (APP_KEY, DB_PASSWORD, B2 keys, ...). Every inherited variable is therefore
 * explicitly removed except a short allowlist needed to run a binary, and the
 * caller adds exactly the variables the child needs.
 */
final class ChildEnvironment
{
    /** Variables a child may inherit. Everything else is removed. */
    private const array INHERITABLE = [
        'PATH', 'HOME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ',
        // Needed on Windows hosts for process creation.
        'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP',
    ];

    /**
     * @param  array<string, string>  $controlled  variables the child must receive
     * @return array<string, string|false> environment for Symfony Process (false removes)
     */
    public static function build(#[\SensitiveParameter] array $controlled = []): array
    {
        $environment = [];
        $allowed = array_map('strtoupper', self::INHERITABLE);

        foreach (self::inheritedNames() as $name) {
            $value = getenv($name);
            $keep = in_array(strtoupper($name), $allowed, true) && is_string($value) && $value !== '';

            $environment[$name] = $keep ? $value : false;
        }

        foreach ($controlled as $name => $value) {
            $environment[$name] = $value;
        }

        return $environment;
    }

    /**
     * @return list<string>
     */
    private static function inheritedNames(): array
    {
        $names = array_keys(getenv());

        foreach ([$_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($value)) {
                    $names[] = (string) $name;
                }
            }
        }

        return array_values(array_unique(array_map('strval', $names)));
    }
}
