<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Exceptions\ResticAuthenticationFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticRepositoryLocked;
use Quraba\Backup\Exceptions\ResticRepositoryUnavailable;
use Quraba\Backup\Exceptions\ResticRepositoryUninitialized;

/**
 * Maps a failed Restic result to the package exception taxonomy.
 *
 * The "repository does not exist" classification is taken ONLY from Restic's
 * dedicated exit code 10. Text heuristics are used solely to classify other
 * failures (credentials, network, missing bucket) and can never produce
 * "uninitialized", so a typo, a network error or bad credentials can never
 * be mistaken for a repository that may be created.
 */
final class ResticErrorClassifier
{
    private const array CREDENTIAL_MARKERS = [
        'access denied', 'accessdenied', 'invalidaccesskeyid', 'signaturedoesnotmatch',
        'the access key id you provided does not exist', 'unauthorized', '403 forbidden',
        'invalid access key', 'malformed access key', 'status code 403', 'status 403',
    ];

    private const array PASSWORD_MARKERS = [
        'wrong password', 'no key found', 'unable to open repository with the provided password',
    ];

    private const array NETWORK_MARKERS = [
        'no such host', 'dial tcp', 'connection refused', 'i/o timeout', 'tls handshake',
        'network is unreachable', 'context deadline exceeded', 'x509:', 'connection reset',
        'temporary failure in name resolution', 'server misbehaving', 'nosuchbucket',
        'bucket does not exist', 'the specified bucket does not exist',
    ];

    public static function classify(ResticResult $result): QurabaBackupException
    {
        $detail = $result->stderr !== '' ? $result->stderr : 'no diagnostic output';
        $detail = self::tail($detail);
        $label = $result->operation->label();
        $haystack = strtolower($result->stderr);

        return match (true) {
            $result->exitCode === ResticResult::EXIT_REPOSITORY_MISSING => new ResticRepositoryUninitialized(sprintf('%s: no Restic repository exists at the configured location (exit 10). %s', $label, $detail)),
            $result->exitCode === ResticResult::EXIT_REPOSITORY_LOCKED => new ResticRepositoryLocked(sprintf('%s: the repository is locked by another process (exit 11). Locks are never removed automatically. %s', $label, $detail)),
            $result->exitCode === ResticResult::EXIT_WRONG_PASSWORD, self::contains($haystack, self::PASSWORD_MARKERS) => ResticAuthenticationFailed::wrongPassword($label.': '.$detail),
            self::contains($haystack, self::CREDENTIAL_MARKERS) => ResticAuthenticationFailed::storageCredentialsRejected($label.': '.$detail),
            self::contains($haystack, self::NETWORK_MARKERS) => new ResticRepositoryUnavailable(sprintf('%s: the repository could not be reached or read. %s', $label, $detail)),
            default => new ResticCommandFailed(sprintf('%s exited with code %d. %s', $label, $result->exitCode, $detail)),
        };
    }

    /**
     * @param  list<string>  $markers
     */
    private static function contains(string $haystack, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function tail(string $text): string
    {
        $text = trim($text);

        return strlen($text) > 1500 ? '…'.substr($text, -1500) : $text;
    }
}
