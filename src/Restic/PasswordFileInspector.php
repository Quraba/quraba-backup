<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Support\PathGuard;

/**
 * Checks the Restic password file without ever returning its contents.
 */
final readonly class PasswordFileInspector
{
    public const int MAX_BYTES = 65536;

    /**
     * @param  list<string>  $forbiddenRoots  directories the file must never live in (e.g. public/)
     */
    public function __construct(private array $forbiddenRoots = []) {}

    /**
     * @return array{usable: bool, problems: list<string>, warnings: list<string>}
     */
    public function inspect(?string $path): array
    {
        $problems = [];
        $warnings = [];

        if ($path === null) {
            return ['usable' => false, 'problems' => ['QURABA_BACKUP_RESTIC_PASSWORD_FILE is not configured.'], 'warnings' => []];
        }

        if (! is_file($path)) {
            return ['usable' => false, 'problems' => [sprintf('Password file [%s] does not exist or is not a regular file.', $path)], 'warnings' => []];
        }

        $real = PathGuard::real($path) ?? $path;

        foreach ($this->forbiddenRoots as $root) {
            $realRoot = PathGuard::real($root);

            if ($realRoot !== null && PathGuard::isWithin($real, $realRoot)) {
                $problems[] = sprintf('Password file [%s] is inside [%s], which may be web-accessible. Move it outside the public directory.', $path, $root);
            }
        }

        if (! is_readable($path)) {
            $problems[] = sprintf('Password file [%s] is not readable by the PHP user.', $path);
        }

        $size = @filesize($path);

        if ($size === false || $size === 0) {
            $problems[] = sprintf('Password file [%s] is empty.', $path);
        } elseif ($size > self::MAX_BYTES) {
            $problems[] = sprintf('Password file [%s] is unexpectedly large.', $path);
        } elseif (is_readable($path)) {
            $contents = (string) @file_get_contents($path, false, null, 0, self::MAX_BYTES);
            $password = rtrim($contents, "\r\n");

            if (trim($password) === '') {
                $problems[] = sprintf('Password file [%s] contains no password.', $path);
            } elseif (strlen($password) < 16) {
                $warnings[] = 'The Restic repository password is shorter than 16 characters.';
            }

            unset($contents, $password);
        }

        if (! PathGuard::isWindows()) {
            $permissions = @fileperms($path);

            if ($permissions !== false) {
                if (($permissions & 0o007) !== 0) {
                    $problems[] = sprintf('Password file [%s] is accessible by other users (mode %04o). Run: chmod 600 %s', $path, $permissions & 0o777, $path);
                } elseif (($permissions & 0o070) !== 0) {
                    $warnings[] = sprintf('Password file [%s] is group-accessible (mode %04o); 0600 is recommended.', $path, $permissions & 0o777);
                }
            }
        }

        return ['usable' => $problems === [], 'problems' => $problems, 'warnings' => $warnings];
    }
}
