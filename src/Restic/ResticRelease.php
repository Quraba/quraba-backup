<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

/**
 * The Restic release this package version is built and tested against.
 *
 * The version is pinned in code, not configuration: upgrading Restic is an
 * explicit package release, never a runtime decision. The SHA-256 digests are
 * the trust anchor for the installer; the release's own SHA256SUMS manifest
 * is downloaded and must agree with them, but can never override them.
 *
 * Digests for v0.19.1 were taken from the official GitHub release assets of
 * restic/restic (published 2026-07-05).
 */
final class ResticRelease
{
    public const string VERSION = '0.19.1';

    /**
     * SHA-256 of the official compressed release archives, keyed by version
     * and then by "{os}_{arch}" as used in the release file names.
     *
     * @var array<string, array<string, string>>
     */
    public const array CHECKSUMS = [
        '0.19.1' => [
            'linux_amd64' => 'f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c',
            'linux_arm64' => 'a5f64aaab53d51e311fa3829124c5b703f2d14cf187d8640b6be3b2b49376465',
            'windows_amd64' => 'da948ad707ed690426473aaba2046cd61f8f90f6f0e7dab6be0d5796531de67d',
        ],
    ];

    public const string DOWNLOAD_BASE_URL = 'https://github.com/restic/restic/releases/download';

    /** Relative release asset names; {version}, {os} and {arch} are substituted. */
    public const string ARCHIVE_TEMPLATE = 'v{version}/restic_{version}_{os}_{arch}.bz2';

    public const string WINDOWS_ARCHIVE_TEMPLATE = 'v{version}/restic_{version}_{os}_{arch}.zip';

    public const string CHECKSUM_MANIFEST_TEMPLATE = 'v{version}/SHA256SUMS';

    /** Restic repository format the package initializes and supports. */
    public const int REPOSITORY_VERSION = 2;
}
