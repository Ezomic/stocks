<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Models\Rule;
use App\Models\Setting;
use App\Services\IbkrClient;
use App\Services\OrderNotifier;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class PlaceOrderAction
{
    public function __construct(
        private readonly IbkrClient $client,
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * A null quantity means the whole position, which is what every caller wanted before
     * rules could sell a part of one.
     */
    public function handle(Position $position, string $side, ?Rule $rule = null, ?float $quantity = null): Order
    {
        $quantity ??= (float) $position->quantity;

        $order = Order::create([
            'position_id' => $position->id,
            'symbol' => $position->symbol,
            'rule_id' => $rule?->id,
            'side' => $side,
            'quantity' => max($quantity, 0),
            'order_type' => 'market',
            'status' => Setting::dryRun() ? 'simulated' : 'pending',
        ]);

        // A ladder step too small to express in whole units must be reported rather than
        // rounded away: doing nothing quietly is indistinguishable from never triggering.
        if ($quantity <= 0) {
            $order->update([
                'status' => 'failed',
                'error_message' => 'The rule asked for '.($rule instanceof Rule ? $rule->sell_pct : '100')
                    .'% of '.rtrim(rtrim($position->quantity, '0'), '.').' '.$position->symbol
                    .', which rounds to less than one unit. Nothing was sent to IBKR.',
            ]);

            $this->notifier->notify($order->refresh(), 'failed');

            return $order;
        }

        // The record of what would have been sent is the whole point of a dry run, so it is
        // written first and the gateway is simply never called.
        if ($order->status === 'simulated') {
            return $order;
        }

        try {
            $payload = [
                'conid' => (int) $position->ibkr_con_id,
                'orderType' => 'MKT',
                'side' => strtoupper($side),
                'quantity' => $quantity,
                'tif' => 'DAY',
            ];

            $response = $this->client->placeOrder($payload);
            $this->guardSuccessful($response);
            $first = $this->firstRow($response->json());

            // IBKR may return a confirmation challenge
            if (isset($first['messageIds'])) {
                $response = $this->client->confirmOrder($this->replyId($first));
                $this->guardSuccessful($response);
                $first = $this->firstRow($response->json());
            }

            $order->update([
                'status' => 'placed',
                'broker_order_id' => $this->brokerOrderId($first, $response),
                'placed_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            $order->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);
        }

        $order->refresh();

        $this->notifier->notify($order, $order->status);

        return $order;
    }

    /**
     * The Http client does not throw on its own, so an expired session or a gateway error
     * would otherwise fall through and be recorded as a successfully placed order.
     */
    private function guardSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            "IBKR returned HTTP {$response->status()}: ".Str::limit($response->body(), 1000)
        );
    }

    /** @param array<int|string, mixed> $first */
    private function replyId(array $first): string
    {
        $replyId = $first['id'] ?? '';
        $replyId = is_scalar($replyId) ? (string) $replyId : '';

        if ($replyId === '') {
            throw new RuntimeException('IBKR returned a confirmation challenge without a reply id.');
        }

        return $replyId;
    }

    /**
     * Without a broker order id the order can never be reconciled by SyncOrderStatusAction,
     * so it must not be recorded as placed. The broker may still have accepted it, which is
     * why the message says so rather than claiming the order was rejected.
     *
     * @param  array<int|string, mixed>  $first
     */
    private function brokerOrderId(array $first, Response $response): string
    {
        $brokerOrderId = $first['order_id'] ?? $first['orderId'] ?? '';
        $brokerOrderId = is_scalar($brokerOrderId) ? (string) $brokerOrderId : '';

        if ($brokerOrderId === '') {
            throw new RuntimeException(
                'IBKR accepted the request but returned no order id, so the order cannot be '
                .'tracked and may or may not be live at the broker. Response: '
                .Str::limit($response->body(), 1000)
            );
        }

        return $brokerOrderId;
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
