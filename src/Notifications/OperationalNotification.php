<?php

declare(strict_types=1);

namespace Quraba\Backup\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Loaded only when a host explicitly enables Laravel Notification delivery. */
final class OperationalNotification extends Notification
{
    /** @param list<string> $channels */
    public function __construct(public readonly OperationalNotice $notice, private readonly array $channels) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, scalar|null> */
    public function toArray(object $notifiable): array
    {
        return ['condition' => $this->notice->condition, 'identity' => $this->notice->identity, ...$this->notice->details];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Quraba Backup: '.$this->notice->condition)
            ->line('Condition: '.$this->notice->condition)
            ->line('Identity: '.$this->notice->identity)
            ->line('Review php artisan quraba:backup:health and the exact run or restore journal.');
    }
}
