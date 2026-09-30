<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quraba\Backup\Restic\ResticRedactor;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Tests\Support\Sentinels;

final class SecretRedactorTest extends TestCase
{
    private function redactor(): SecretRedactor
    {
        return SecretRedactor::fromSecrets(Sentinels::all());
    }

    public function test_known_secrets_and_their_encodings_are_masked(): void
    {
        $text = implode(' | ', [
            'raw '.Sentinels::B2_SECRET,
            'url '.rawurlencode(Sentinels::B2_SECRET),
            'form '.urlencode(Sentinels::B2_SECRET),
            'json '.json_encode(['k' => Sentinels::B2_SECRET]),
            'restic '.Sentinels::RESTIC_PASSWORD,
            'db '.Sentinels::DB_PASSWORD,
            'app '.Sentinels::APP_KEY,
            'archive '.Sentinels::ARCHIVE_PASSWORD,
            'key id '.Sentinels::B2_KEY_ID,
        ]);

        $redacted = $this->redactor()->redact($text);

        Sentinels::assertAbsent($redacted, 'redacted text');
        self::assertStringContainsString(SecretRedactor::MASK, $redacted);
        self::assertStringContainsString('restic ', $redacted, 'Surrounding diagnostics must survive.');
    }

    public function test_structural_patterns_mask_unknown_credentials(): void
    {
        $redactor = SecretRedactor::fromSecrets([]);

        $cases = [
            's3:https://AKIDEXAMPLE:unknown-secret@s3.example.com/bucket' => 'unknown-secret',
            'GET https://host/obj?X-Amz-Signature=deadbeefcafe&X-Amz-Credential=AKID/2026' => 'deadbeefcafe',
            'AWS_SECRET_ACCESS_KEY=topsecretvalue other' => 'topsecretvalue',
            'DB_PASSWORD="quoted secret value"' => 'quoted secret value',
            '{"password":"json-secret","user":"x"}' => 'json-secret',
            'Authorization: Bearer abc.def.ghi' => 'abc.def.ghi',
            'key AKIAABCDEFGHIJKLMNOP used' => 'AKIAABCDEFGHIJKLMNOP',
        ];

        foreach ($cases as $input => $secret) {
            $output = $redactor->redact($input);
            self::assertStringNotContainsString($secret, $output, $input);
        }
    }

    public function test_password_file_path_remains_visible_for_diagnostics(): void
    {
        $output = SecretRedactor::fromSecrets([])->redact('RESTIC_PASSWORD_FILE=/home/site/.quraba-backup/restic-password');

        self::assertStringContainsString('/home/site/.quraba-backup/restic-password', $output);
    }

    public function test_nested_arrays_are_redacted_and_keys_preserved(): void
    {
        $result = $this->redactor()->redactArray(['a' => ['b' => 'x '.Sentinels::B2_SECRET], 'n' => 5]);

        self::assertSame(5, $result['n']);
        Sentinels::assertAbsent((string) json_encode($result), 'array');
    }

    public function test_short_secrets_are_still_redacted(): void
    {
        self::assertStringNotContainsString('pw', SecretRedactor::fromSecrets(['pw'])->redact('the pw is pw'));
    }

    public function test_restic_diagnostic_strips_ansi_and_keeps_tail(): void
    {
        $redactor = new ResticRedactor($this->redactor());
        $output = $redactor->diagnostic("\e[31mFatal:\e[0m ".str_repeat('x', 50).' '.Sentinels::RESTIC_PASSWORD.' end', 40);

        self::assertStringNotContainsString("\e[", $output);
        self::assertStringEndsWith('end', $output);
        Sentinels::assertAbsent($output, 'diagnostic');
    }
}
