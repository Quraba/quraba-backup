<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A catalog record was asked to move to a state its state machine does not allow.
 */
final class IllegalStateTransition extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'state.illegal_transition';
}
