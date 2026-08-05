<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;

class SyncOrderStatusAction
{
    public function __construct(private readonly IbkrClient $client) {}

    public function handle(): void
    {
        $pending = Order::with('position')
            ->where('status', 'placed')
            ->whereNotNull('broker_order_id')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $response = $this->client->getOrders();

        if (! $response->successful()) {
            return;
        }

        /** @var array<int, array<string, mixed>> $orders */
        $orders = $response->json('orders') ?? [];
        $brokerOrders = collect($orders)
            ->keyBy(function (array $o): string {
                $key = $o['orderId'] ?? $o['order_id'] ?? '';

                return is_scalar($key) ? (string) $key : '';
            });

        foreach ($pending as $order) {
            $broker = $brokerOrders->get($order->broker_order_id);

            if (! $broker) {
                continue;
            }

            $statusRaw = $broker['status'] ?? '';
            $status = strtolower(is_string($statusRaw) ? $statusRaw : '');

            if ($status === 'filled') {
                $order->update([
                    'status' => 'filled',
                    'filled_at' => Carbon::now(),
                    'fill_price' => $broker['avgPrice'] ?? $broker['price'] ?? null,
                ]);

                $this->applyFillToPosition($order, $this->filledQuantity($broker, $order));
            } elseif (in_array($status, ['cancelled', 'inactive'])) {
                $order->update(['status' => 'cancelled']);
            }
        }
    }

    /**
     * The local quantity is what every rule evaluation measures against, so leaving it at the
     * pre-sale figure makes the same position sell again on the next cycle. Only a status
     * transition reaches this, so a fill is never applied twice.
     */
    private function applyFillToPosition(Order $order, float $filledQuantity): void
    {
        $position = $order->position;

        if (! $position instanceof Position || $filledQuantity <= 0.0) {
            return;
        }

        $delta = $order->side === 'sell' ? -$filledQuantity : $filledQuantity;

        $position->update([
            'quantity' => max(0.0, (float) $position->quantity + $delta),
        ]);
    }

    /** @param array<string, mixed> $broker */
    private function filledQuantity(array $broker, Order $order): float
    {
        $filled = $broker['filledQuantity'] ?? $broker['cumFill'] ?? null;

        return is_numeric($filled) ? (float) $filled : (float) $order->quantity;
    }
}
