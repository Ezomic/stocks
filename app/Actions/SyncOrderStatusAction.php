<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;

class SyncOrderStatusAction
{
    public function __construct(private readonly IbkrClient $client) {}

    public function handle(): void
    {
        $pending = Order::where('status', 'placed')->whereNotNull('broker_order_id')->get();

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
            } elseif (in_array($status, ['cancelled', 'inactive'])) {
                $order->update(['status' => 'cancelled']);
            }
        }
    }
}
