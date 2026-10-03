<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Foundation\Application;
use Quraba\Backup\Exceptions\EnvironmentUnsupported;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Restic\PlatformDetector;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Throwable;

final readonly class RuntimeChecks implements DoctorCheck
{
    /** Laravel majors this package version is verified against. */
    public const array SUPPORTED_LARAVEL_MAJORS = [13];

    private const array REQUIRED_EXTENSIONS = ['json', 'pdo', 'openssl', 'ctype', 'mbstring', 'filter', 'hash'];

    public function __construct(
        private Application $app,
        private PlatformDetector $platforms,
        private ProcessFactory $processes,
    ) {}

    public function name(): string
    {
        return 'runtime';
    }

    public function run(): array
    {
        return [
            $this->php(),
            $this->laravel(),
            $this->operatingSystem(),
            $this->architecture(),
            $this->processExecution(),
            ...$this->extensions(),
            $this->outboundHttpCapability(),
        ];
    }

    private function php(): CheckResult
    {
        // Evaluated at runtime: the host may run a different PHP than Composer resolved for.
        return version_compare(PHP_VERSION, '8.5.0', '>=')
            ? CheckResult::pass('runtime.php', 'PHP version', PHP_VERSION)
            : CheckResult::fail('runtime.php', 'PHP version', sprintf('PHP %s is too old; PHP 8.5+ is required.', PHP_VERSION));
    }

    private function laravel(): CheckResult
    {
        $version = $this->app->version();
        $major = (int) explode('.', $version)[0];

        return in_array($major, self::SUPPORTED_LARAVEL_MAJORS, true)
            ? CheckResult::pass('runtime.laravel', 'Laravel version', $version)
            : CheckResult::warn('runtime.laravel', 'Laravel version', sprintf('Laravel %s has not been verified with this package version (verified: %s).', $version, implode(', ', self::SUPPORTED_LARAVEL_MAJORS)));
    }

    private function operatingSystem(): CheckResult
    {
        $family = $this->platforms->osFamily();

        return in_array($family, ['Linux', 'Windows'], true)
            ? CheckResult::pass('runtime.os', 'Operating system', $family)
            : CheckResult::fail('runtime.os', 'Operating system', sprintf('%s is not a supported host; supported: Linux and Windows.', $family));
    }

    private function architecture(): CheckResult
    {
        try {
            $platform = $this->platforms->detect();
        } catch (EnvironmentUnsupported $exception) {
            return CheckResult::fail('runtime.architecture', 'CPU architecture', $exception->getMessage());
        }

        return CheckResult::pass('runtime.architecture', 'CPU architecture', $platform->label(), ['machine' => $this->platforms->machine()]);
    }

    private function processExecution(): CheckResult
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (! function_exists($function) || in_array($function, $disabled, true)) {
                return CheckResult::fail('runtime.proc_open', 'Process execution', sprintf('%s() is disabled; Symfony Process cannot run Restic or database tools. Ask the host to enable it for PHP CLI.', $function));
            }
        }

        if (! is_file(PHP_BINARY)) {
            return CheckResult::warn('runtime.proc_open', 'Process execution', 'proc_open() is available, but the PHP binary path is unknown so a live probe was skipped.');
        }

        try {
            $process = $this->processes->make([PHP_BINARY, '-r', 'echo "quraba-ok";'], null, ChildEnvironment::build(), 20.0);
            $process->run();
        } catch (Throwable $exception) {
            return CheckResult::fail('runtime.proc_open', 'Process execution', 'A child process could not be started: '.$exception->getMessage());
        }

        return $process->isSuccessful() && trim($process->getOutput()) === 'quraba-ok'
            ? CheckResult::pass('runtime.proc_open', 'Process execution', 'Symfony Process can start child processes.')
            : CheckResult::fail('runtime.proc_open', 'Process execution', 'A child process started but did not behave as expected.');
    }

    /**
     * @return list<CheckResult>
     */
    private function extensions(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $extension): bool => ! extension_loaded($extension)));

        $results = [
            $missing === []
                ? CheckResult::pass('runtime.extensions', 'Required PHP extensions', implode(', ', self::REQUIRED_EXTENSIONS))
                : CheckResult::fail('runtime.extensions', 'Required PHP extensions', 'Missing: '.implode(', ', $missing)),
        ];

        $results[] = extension_loaded('zip')
            ? CheckResult::pass('runtime.ext_zip', 'zip extension', 'Available for encrypted application archives.')
            : CheckResult::warn('runtime.ext_zip', 'zip extension', 'Missing. It will be required once application archive backups are enabled.');

        $results[] = $this->platforms->osFamily() === 'Windows'
            ? CheckResult::skip('runtime.ext_posix', 'posix extension', 'Not applicable on Windows; NTFS permissions use ACLs.')
            : (extension_loaded('posix')
            ? CheckResult::pass('runtime.ext_posix', 'posix extension', 'Available.')
            : CheckResult::warn('runtime.ext_posix', 'posix extension', 'Missing; permission diagnostics are less precise.'));

        return $results;
    }

    private function outboundHttpCapability(): CheckResult
    {
        if (extension_loaded('curl')) {
            return CheckResult::pass('runtime.http_client', 'HTTP client', 'curl extension available.');
        }

        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
            ? CheckResult::warn('runtime.http_client', 'HTTP client', 'curl is missing; falling back to PHP stream transport.')
            : CheckResult::fail('runtime.http_client', 'HTTP client', 'Neither the curl extension nor allow_url_fopen is available; outbound HTTPS is impossible.');
    }
}
