<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use JsonException;
use Quraba\Backup\Exceptions\ResticCommandFailed;

/**
 * Central parser for Restic's JSON output.
 *
 * Restic emits either one JSON document (e.g. `snapshots --json`,
 * `cat config`) or newline-delimited JSON messages with a `message_type`
 * (e.g. `backup --json`, `restore --json`). Human-readable output is never
 * parsed when JSON is available.
 */
final class ResticJson
{
    /**
     * @return array<array-key, mixed>
     */
    public static function document(string $output): array
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            throw new ResticCommandFailed('Restic returned no JSON output.');
        }

        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new ResticCommandFailed('Restic returned malformed JSON: '.$exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw new ResticCommandFailed('Restic returned a JSON scalar where a document was expected.');
        }

        return $decoded;
    }

    /**
     * Parses newline-delimited JSON messages. Blank lines are ignored; any
     * other non-JSON line makes the output untrustworthy and is rejected.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function lines(string $output): array
    {
        $messages = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $number => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException) {
                throw new ResticCommandFailed(sprintf('Restic output line %d is not valid JSON.', $number + 1));
            }

            if (! is_array($decoded)) {
                throw new ResticCommandFailed(sprintf('Restic output line %d is not a JSON object.', $number + 1));
            }

            $messages[] = $decoded;
        }

        return $messages;
    }

    /**
     * @param  list<array<array-key, mixed>>  $messages
     * @return list<array<array-key, mixed>>
     */
    public static function ofType(array $messages, string $type): array
    {
        return array_values(array_filter(
            $messages,
            static fn (array $message): bool => ($message['message_type'] ?? null) === $type,
        ));
    }
}
