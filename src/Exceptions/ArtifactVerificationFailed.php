<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * An artifact failed verification and must not be trusted.
 */
final class ArtifactVerificationFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'artifact.verification_failed';
}
