<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Position;
use App\Models\Rule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ThresholdCrossed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Position $position,
        public readonly Rule $rule,
        public readonly string $threshold,
        public readonly float $price,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = config('notifications.channels');

        return is_array($channels) ? array_values(array_filter($channels, 'is_string')) : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $symbol = $this->position->symbol;
        $price = $this->position->currency.' '.number_format($this->price, 2);
        $label = $this->threshold === 'take_profit' ? 'take-profit' : 'stop-loss';

        return (new MailMessage)
            ->subject("{$symbol} crossed its {$label} level at {$price}")
            ->line("{$symbol} is at {$price}, which crosses the {$label} level on a rule set to alert only.")
            ->line('No order was placed. This rule watches the level and tells you; it does not trade.')
            ->action('Open position', url("/positions/{$this->position->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'position_id' => $this->position->id,
            'symbol' => $this->position->symbol,
            'threshold' => $this->threshold,
            'price' => $this->price,
        ];
    }
}
