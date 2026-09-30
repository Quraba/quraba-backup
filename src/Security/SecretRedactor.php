<?php

declare(strict_types=1);

namespace Quraba\Backup\Security;

use Closure;

/**
 * Removes secrets from any text before it can reach logs, the catalog,
 * exception messages or console output.
 *
 * Two layers are applied:
 *  1. every package-known secret value (and its URL/JSON encoded forms);
 *  2. structural patterns that catch credentials the package does not know
 *     by value (URL user-info, signed query strings, KEY=value assignments).
 *
 * Secrets of any length are redacted. A very short secret makes output less
 * readable, but leaking it is never the acceptable trade-off.
 */
final class SecretRedactor
{
    public const string MASK = '[REDACTED]';

    /** @var Closure(): iterable<mixed> */
    private readonly Closure $secrets;

    /**
     * @param  Closure(): iterable<mixed>  $secrets  resolved on every call so rotated secrets are honoured
     */
    public function __construct(Closure $secrets)
    {
        $this->secrets = $secrets;
    }

    /**
     * @param  list<string|null>  $secrets
     */
    public static function fromSecrets(array $secrets): self
    {
        return new self(static fn (): array => $secrets);
    }

    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = $this->redactKnownValues($text);

        return $this->redactPatterns($text);
    }

    /**
     * Redacts every string in a (nested) array, preserving keys.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values
     * @return array<TKey, mixed>
     */
    public function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $this->redact($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->redactArray($value);
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function variants(): array
    {
        $variants = [];

        foreach (($this->secrets)() as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            foreach ([$secret, trim($secret)] as $value) {
                if ($value === '') {
                    continue;
                }

                $variants[] = $value;
                $variants[] = rawurlencode($value);
                $variants[] = urlencode($value);

                $json = json_encode($value, JSON_UNESCAPED_UNICODE);
                if (is_string($json) && strlen($json) > 2) {
                    $variants[] = substr($json, 1, -1);
                }

                $escapedSlashes = json_encode($value);
                if (is_string($escapedSlashes) && strlen($escapedSlashes) > 2) {
                    $variants[] = substr($escapedSlashes, 1, -1);
                }
            }
        }

        $variants = array_values(array_unique($variants));

        // Longest first so a secret containing another secret is fully masked.
        usort($variants, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $variants;
    }

    private function redactKnownValues(string $text): string
    {
        foreach ($this->variants() as $variant) {
            $text = str_replace($variant, self::MASK, $text);
        }

        return $text;
    }

    private function redactPatterns(string $text): string
    {
        $mask = self::MASK;

        $patterns = [
            // scheme://user:password@host  (also s3:https://...)
            '~(\b[a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]*@~i' => '$1'.$mask.'@',
            // Signed URLs and token query parameters.
            '~([?&](?:X-Amz-(?:Signature|Credential|Security-Token)|Signature|AWSAccessKeyId|access_token|token|sig)=)[^&\s"\']+~i' => '$1'.$mask,
            // Authorization headers.
            '~(\bAuthorization\s*:\s*)(?:[A-Za-z0-9\-]+\s+)?[^\s"\']+~i' => '$1'.$mask,
            // KEY=value / KEY: value assignments for credential-looking names.
            '~\b((?:AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|AWS_SESSION_TOKEN|B2_ACCOUNT_ID|B2_ACCOUNT_KEY|[A-Z0-9_]*(?:PASSWORD|SECRET|APPLICATION_KEY|TOKEN|APP_KEY))\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;]+)~' => '$1'.$mask,
            // JSON credential members.
            '~("(?:password|secret|token|application_key|secret_access_key|access_key_id)"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"~i' => '$1"'.$mask.'"',
            // AWS-style access key identifiers.
            '~\b(?:AKIA|ASIA)[0-9A-Z]{16}\b~' => $mask,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
