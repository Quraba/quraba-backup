<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

enum RepositoryState: string
{
    case NotConfigured = 'not_configured';
    case Ready = 'ready';
    case Uninitialized = 'uninitialized';
    case WrongPassword = 'wrong_password';
    case CredentialsRejected = 'credentials_rejected';
    case Unreachable = 'unreachable';
    case Locked = 'locked';
    case Error = 'error';

    public function isReady(): bool
    {
        return $this === self::Ready;
    }
}
