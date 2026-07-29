<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Models\Rule;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;

class PlaceOrderAction
{
    public function __construct(private readonly IbkrClient $client) {}

    public function handle(Position $position, string $side, ?Rule $rule = null): Order
    {
        $order = Order::create([
            'position_id' => $position->id,
            'rule_id' => $rule?->id,
            'side' => $side,
            'quantity' => $position->quantity,
            'order_type' => 'market',
            'status' => 'pending',
        ]);

        try {
            $payload = [
                'conid' => (int) $position->ibkr_con_id,
                'orderType' => 'MKT',
                'side' => strtoupper($side),
                'quantity' => (float) $position->quantity,
                'tif' => 'DAY',
            ];

            $response = $this->client->placeOrder($payload);
            $first = $this->firstRow($response->json());

            // IBKR may return a confirmation challenge
            if (isset($first['messageIds'])) {
                $replyId = $first['id'] ?? '';
                $response = $this->client->confirmOrder(is_scalar($replyId) ? (string) $replyId : '');
                $first = $this->firstRow($response->json());
            }

            $brokerOrderId = $first['order_id'] ?? $first['orderId'] ?? null;

            $order->update([
                'status' => 'placed',
                'broker_order_id' => is_scalar($brokerOrderId) ? (string) $brokerOrderId : '',
                'placed_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            $order->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $order->refresh();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function firstRow(mixed $body): array
    {
        if (is_array($body) && isset($body[0]) && is_array($body[0])) {
            return $body[0];
        }

        return [];
    }
}
