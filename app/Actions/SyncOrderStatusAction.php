<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Services\IbkrClient;
use App\Services\OrderNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SyncOrderStatusAction
{
    public function __construct(
        private readonly IbkrClient $client,
        private readonly OrderNotifier $notifier,
    ) {}

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

        $unreported = new Collection;

        foreach ($pending as $order) {
            $broker = $brokerOrders->get($order->broker_order_id);

            if (! $broker) {
                $unreported->push($order);

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
                $this->notifier->notify($order->refresh(), 'filled');
            } elseif (in_array($status, ['cancelled', 'inactive'])) {
                $order->update(['status' => 'cancelled']);
            }
        }

        $this->reconcileAbandoned($unreported->filter(fn (Order $order): bool => $this->isPastDeadline($order)));
    }

    /**
     * An order the broker has stopped reporting never leaves `placed` on its own, and rule
     * evaluation skips any position with an order still in flight. Left alone, one order
     * ageing out of the broker's list disables that position permanently.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function reconcileAbandoned(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $brokerQuantities = $this->brokerQuantitiesByConid();

        // Without the broker's own figures there is nothing to reconcile against, and guessing
        // is what this whole change exists to avoid. Try again on the next cycle.
        if ($brokerQuantities === null) {
            return;
        }

        foreach ($orders as $order) {
            $order->update([
                'status' => 'unreconciled',
                'error_message' => $this->reconcile($order, $brokerQuantities),
            ]);

            $this->notifier->notify($order->refresh(), 'unreconciled');
        }
    }

    /**
     * @param  Collection<string, float>  $brokerQuantities
     */
    private function reconcile(Order $order, Collection $brokerQuantities): string
    {
        $note = 'The broker stopped reporting this order before it was reconciled.';
        $position = $order->position;
        $conid = $position instanceof Position ? (string) $position->ibkr_con_id : '';

        if (! $position instanceof Position || ! $brokerQuantities->has($conid)) {
            return $note.' The broker reported no position for this contract either, so the'
                .' quantity could not be confirmed. Check the account before trading it again.';
        }

        $brokerQuantity = (float) $brokerQuantities->get($conid);
        $localQuantity = (float) $position->quantity;

        if (abs($brokerQuantity - $localQuantity) < 0.000001) {
            return $note.' The broker position matches the local quantity, so it most likely never filled.';
        }

        $position->update(['quantity' => $brokerQuantity]);

        return $note." Position quantity corrected from {$localQuantity} to {$brokerQuantity} from the broker's own figure.";
    }

    /**
     * @return Collection<string, float>|null
     */
    private function brokerQuantitiesByConid(): ?Collection
    {
        $response = $this->client->portfolioPositions();

        if (! $response->successful()) {
            return null;
        }

        $rows = $response->json();

        if (! is_array($rows)) {
            return null;
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['conid']))
            ->mapWithKeys(function (array $row): array {
                $conid = $row['conid'];
                $quantity = $row['position'] ?? 0;

                return [
                    (is_scalar($conid) ? (string) $conid : '') => is_numeric($quantity) ? (float) $quantity : 0.0,
                ];
            });
    }

    private function isPastDeadline(Order $order): bool
    {
        $placedAt = $order->placed_at ?? $order->created_at;

        return $placedAt->addMinutes(Order::reconcileTimeoutMinutes())->isPast();
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
