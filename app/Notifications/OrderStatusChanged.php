<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly string $event,
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
        $order = $this->order;
        $symbol = $this->symbol();
        $quantity = rtrim(rtrim($order->quantity, '0'), '.');
        $side = strtoupper($order->side);

        $mail = (new MailMessage)
            ->subject("Order {$this->event}: {$side} {$quantity} {$symbol}")
            ->line("A {$side} order for {$quantity} {$symbol} is now {$this->event}.")
            ->line("Price: {$this->price()}")
            ->line("Triggered by: {$this->trigger()}");

        if ($this->event === 'failed' && $order->error_message !== null) {
            $mail->line("Reason: {$order->error_message}");
        }

        return $mail->action('View orders', url('/orders'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'event' => $this->event,
            'symbol' => $this->symbol(),
            'side' => $this->order->side,
            'quantity' => $this->order->quantity,
            'price' => $this->price(),
        ];
    }

    private function symbol(): string
    {
        $position = $this->order->position;

        return $position instanceof Position ? $position->symbol : 'unknown';
    }

    private function price(): string
    {
        $position = $this->order->position;
        $currency = $position instanceof Position ? $position->currency : '';

        if ($this->order->fill_price !== null) {
            return trim($currency.' '.$this->order->fill_price).' (filled)';
        }

        $snapshot = $position instanceof Position ? PriceSnapshot::latestFor($position->symbol) : null;

        return $snapshot === null
            ? 'unknown'
            : trim($currency.' '.$snapshot->price).' (last seen)';
    }

    private function trigger(): string
    {
        $rule = $this->order->rule;

        if ($rule === null) {
            return 'manual or unattributed';
        }

        $parts = [];

        if ($rule->take_profit_pct !== null) {
            $parts[] = "take profit {$rule->take_profit_pct}%";
        }

        if ($rule->stop_loss_pct !== null) {
            $parts[] = "stop loss {$rule->stop_loss_pct}%";
        }

        $description = $parts === [] ? 'no thresholds set' : implode(', ', $parts);

        return ($rule->isGlobal() ? 'global rule' : 'position rule')." ({$description})";
    }
}
