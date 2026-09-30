<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A path or deletion request would escape or cross an operation workspace boundary.
 */
final class WorkspaceViolation extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'workspace.violation';
}
