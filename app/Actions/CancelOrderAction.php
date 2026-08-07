<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class CancelOrderAction
{
    public function __construct(private readonly IbkrClient $client) {}

    /**
     * IBKR only acknowledges that the request was submitted; the order moves to cancelled in
     * its own time. So this records that a cancellation is in flight and lets the regular
     * status sync confirm the outcome, rather than claiming the order is gone.
     *
     * @return string the message to show the operator
     */
    public function handle(Order $order): string
    {
        if (! $this->isCancellable($order)) {
            throw new RuntimeException('Only an order that is still live at the broker can be cancelled.');
        }

        $response = $this->client->cancelOrder((string) $order->broker_order_id);

        if (! $response->successful()) {
            throw new RuntimeException(
                "IBKR refused the cancellation with HTTP {$response->status()}: ".Str::limit($response->body(), 500)
            );
        }

        $order->update(['cancel_requested_at' => Carbon::now()]);

        return "Cancellation requested for order {$order->broker_order_id}. The status updates on the next order sync.";
    }

    public function isCancellable(Order $order): bool
    {
        return $order->status === 'placed'
            && $order->broker_order_id !== null
            && $order->broker_order_id !== '';
    }
}
