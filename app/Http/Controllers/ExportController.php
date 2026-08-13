<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Streamed rather than built in memory: orders is the table that grows without bound, and
     * a tax-time export is exactly when it is largest.
     */
    public function orders(Request $request): StreamedResponse
    {
        $query = Order::query()->with('position')->latest('id');

        $status = $request->string('status')->toString();

        if ($status !== '') {
            $query->where('status', $status);
        }

        return $this->stream('orders', [
            'id', 'symbol', 'side', 'quantity', 'remaining_quantity', 'order_type', 'status',
            'broker_order_id', 'placed_at', 'filled_at', 'fill_price', 'cost_basis',
            'realised_profit', 'currency', 'error_message',
        ], $query, fn (Order $order): array => [
            $order->id,
            $order->symbol,
            $order->side,
            $order->quantity,
            $order->remaining_quantity,
            $order->order_type,
            $order->status,
            $order->broker_order_id,
            $order->placed_at?->toIso8601String(),
            $order->filled_at?->toIso8601String(),
            $order->fill_price,
            $order->cost_basis,
            $order->realisedProfit(),
            $order->currency,
            $order->error_message,
        ]);
    }

    public function positions(): StreamedResponse
    {
        return $this->stream('positions', [
            'id', 'symbol', 'account_mode', 'broker_account_id', 'quantity', 'broker_quantity',
            'avg_buy_price', 'currency', 'market', 'ibkr_con_id', 'last_triggered_at', 'notes',
        ], Position::query()->orderBy('symbol'), fn (Position $position): array => [
            $position->id,
            $position->symbol,
            $position->account_mode,
            $position->broker_account_id,
            $position->quantity,
            $position->broker_quantity,
            $position->avg_buy_price,
            $position->currency,
            $position->market,
            $position->ibkr_con_id,
            $position->last_triggered_at?->toIso8601String(),
            $position->notes,
        ]);
    }

    /**
     * @template TModel of Model
     *
     * @param  array<int, string>  $headers
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<int, scalar|null>  $row
     */
    private function stream(string $name, array $headers, Builder $query, callable $row): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $query, $row): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headers);

            foreach ($query->cursor() as $record) {
                fputcsv($handle, $row($record));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
