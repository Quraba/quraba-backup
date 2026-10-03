<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quraba\Backup\Exceptions\ResticInstallationFailed;
use Quraba\Backup\Restic\Installer\VerifiedZipExtractor;
use ZipArchive;

final class VerifiedZipExtractorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quraba-zip-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** @param array<string, string> $entries */
    private function archive(array $entries): string
    {
        $path = $this->directory.'/release.zip';
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    public function test_exact_single_executable_is_extracted(): void
    {
        $source = $this->archive(['restic_0.19.1_windows_amd64.exe' => 'binary']);
        (new VerifiedZipExtractor)->extract($source, $this->directory.'/restic.exe', 'restic_0.19.1_windows_amd64.exe', 100);
        self::assertSame('binary', file_get_contents($this->directory.'/restic.exe'));
    }

    public function test_other_entries_and_oversized_output_are_refused(): void
    {
        foreach ([
            ['restic_0.19.1_windows_amd64.exe' => 'binary', 'other.txt' => 'junk'],
            ['../restic_0.19.1_windows_amd64.exe' => 'binary'],
            ['restic_0.19.1_windows_amd64.exe' => str_repeat('x', 101)],
        ] as $entries) {
            $source = $this->archive($entries);
            try {
                (new VerifiedZipExtractor)->extract($source, $this->directory.'/restic.exe', 'restic_0.19.1_windows_amd64.exe', 100);
                self::fail('Unsafe ZIP must be refused.');
            } catch (ResticInstallationFailed) {
                self::assertFileDoesNotExist($this->directory.'/restic.exe');
            }
        }
    }
}
