<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class OrderNotifier
{
    /**
     * Order events happen inside scheduled commands, so a notification problem must never take
     * the run down with it. The log line is written first and unconditionally, which keeps an
     * audit trail even when delivery is switched off or broken.
     */
    public function notify(Order $order, string $event): void
    {
        Log::info("Order {$event}", [
            'order_id' => $order->id,
            'symbol' => $order->position?->symbol,
            'side' => $order->side,
            'quantity' => $order->quantity,
        ]);

        if (! $this->shouldNotify($event)) {
            return;
        }

        try {
            Notification::send(User::all(), new OrderStatusChanged($order, $event));
        } catch (\Throwable $e) {
            Log::warning('Order notification could not be dispatched: '.$e->getMessage());
        }
    }

    private function shouldNotify(string $event): bool
    {
        if (config('notifications.enabled') !== true) {
            return false;
        }

        $events = config('notifications.events');
        $channels = config('notifications.channels');

        return is_array($events)
            && in_array($event, $events, true)
            && is_array($channels)
            && $channels !== [];
    }
}
