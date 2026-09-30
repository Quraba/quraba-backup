<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Exceptions\ResticAuthenticationFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticRepositoryLocked;
use Quraba\Backup\Exceptions\ResticRepositoryUnavailable;
use Quraba\Backup\Exceptions\ResticRepositoryUninitialized;
use Quraba\Backup\Restic\ResticErrorClassifier;
use Quraba\Backup\Restic\ResticJson;
use Quraba\Backup\Restic\ResticOperation;
use Quraba\Backup\Restic\ResticResult;
use Quraba\Backup\Restic\ResticSnapshot;
use Quraba\Backup\Restic\ResticVersionInfo;

final class ResticOutputTest extends TestCase
{
    public function test_newline_delimited_json_is_parsed_and_filtered(): void
    {
        $stdout = implode("\n", [
            '{"message_type":"status","percent_done":0.5}',
            '',
            '{"message_type":"summary","snapshot_id":"'.str_repeat('c', 64).'","files_new":3}',
        ])."\n";

        $result = new ResticResult(ResticOperation::Backup, 0, $stdout, '', 1.2);

        self::assertCount(2, $result->jsonLines());
        self::assertCount(1, $result->messages('status'));
        self::assertSame(str_repeat('c', 64), $result->summary()['snapshot_id'] ?? null);
        self::assertTrue($result->successful());
    }

    public function test_non_json_lines_are_rejected(): void
    {
        $this->expectException(ResticCommandFailed::class);
        ResticJson::lines("{\"message_type\":\"status\"}\nscanned 3 files\n");
    }

    public function test_single_document_parsing(): void
    {
        self::assertSame([], ResticJson::document("[]\n"));

        $this->expectException(ResticCommandFailed::class);
        ResticJson::document('{broken');
    }

    public function test_version_document(): void
    {
        $info = ResticVersionInfo::fromJson(['message_type' => 'version', 'version' => '0.19.1', 'go_os' => 'linux', 'go_arch' => 'amd64']);

        self::assertTrue($info->matches('0.19.1'));
        self::assertFalse($info->matches('0.19.0'));

        $this->expectException(ResticCommandFailed::class);
        ResticVersionInfo::fromJson(['version' => 'restic 0.19.1']);
    }

    public function test_snapshots_require_full_ids(): void
    {
        $snapshot = ResticSnapshot::fromJson(['id' => str_repeat('d', 64), 'short_id' => 'dddddddd', 'time' => '2026-09-30T02:00:00.123+03:00', 'tags' => ['run:x'], 'paths' => ['/a']]);

        self::assertSame('2026-09-29T23:00:00Z', $snapshot->time->toIso8601ZuluString());
        self::assertTrue($snapshot->hasTag('run:x'));

        $this->expectException(ResticCommandFailed::class);
        ResticSnapshot::fromJson(['id' => 'dddddddd', 'time' => '2026-09-30T02:00:00Z']);
    }

    /**
     * @return iterable<string, array{int, string, class-string, string}>
     */
    public static function failures(): iterable
    {
        yield 'exit 10 is the only "missing repository" signal' => [10, 'Fatal: repository does not exist', ResticRepositoryUninitialized::class, 'restic.repository_uninitialized'];
        yield 'exit 11 locked' => [11, 'repository is already locked', ResticRepositoryLocked::class, 'restic.repository_locked'];
        yield 'exit 12 wrong password' => [12, 'Fatal: wrong password or no key found', ResticAuthenticationFailed::class, 'restic.wrong_password'];
        yield 'rejected credentials' => [1, 'InvalidAccessKeyId: The AWS Access Key Id you provided does not exist', ResticAuthenticationFailed::class, 'restic.storage_credentials_rejected'];
        yield 'network failure' => [1, 'dial tcp: lookup s3.example.com: no such host', ResticRepositoryUnavailable::class, 'restic.repository_unavailable'];
        yield 'missing bucket is not a missing repository' => [1, 'NoSuchBucket: The specified bucket does not exist', ResticRepositoryUnavailable::class, 'restic.repository_unavailable'];
        yield 'text claiming absence with exit 1 is NOT uninitialized' => [1, 'Is there a repository at the following location? repository does not exist', ResticCommandFailed::class, 'restic.command_failed'];
        yield 'generic failure' => [1, 'something else', ResticCommandFailed::class, 'restic.command_failed'];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('failures')]
    public function test_failures_are_classified_conservatively(int $exit, string $stderr, string $class, string $code): void
    {
        $exception = ResticErrorClassifier::classify(new ResticResult(ResticOperation::CatConfig, $exit, '', $stderr, 0.1));

        self::assertInstanceOf($class, $exception);
        self::assertSame($code, $exception->failureCode());
    }

    public function test_operations_have_positive_timeout_classes(): void
    {
        foreach (ResticOperation::cases() as $operation) {
            self::assertNotSame('', $operation->timeoutClass());
        }

        self::assertFalse(ResticOperation::Version->needsRepository());
        self::assertTrue(ResticOperation::Forget->isWrite());
    }
}
