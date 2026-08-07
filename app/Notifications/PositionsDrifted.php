<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PositionsDrifted extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<int, string> $symbols */
    public function __construct(public readonly array $symbols) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = config('notifications.channels');

        return is_array($channels) ? array_values(array_filter($channels, 'is_string')) : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $list = implode(', ', $this->symbols);
        $count = count($this->symbols);

        return (new MailMessage)
            ->subject("Position quantity differs from IBKR: {$list}")
            ->line("The broker reports a different quantity than this app holds for {$count} ".
                ($count === 1 ? 'position' : 'positions').": {$list}.")
            ->line('Rules for those positions are being measured against the local number, so check them before the next trade.')
            ->action('Open dashboard', url('/'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['symbols' => $this->symbols];
    }
}
