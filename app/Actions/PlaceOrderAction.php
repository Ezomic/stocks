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
            $body = $response->json();

            // IBKR may return a confirmation challenge
            if (isset($body[0]['messageIds'])) {
                $replyId = $body[0]['id'];
                $response = $this->client->confirmOrder($replyId);
                $body = $response->json();
            }

            $brokerOrderId = $body[0]['order_id'] ?? $body[0]['orderId'] ?? null;

            $order->update([
                'status' => 'placed',
                'broker_order_id' => (string) $brokerOrderId,
                'placed_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            $order->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $order->fresh();
    }
}
