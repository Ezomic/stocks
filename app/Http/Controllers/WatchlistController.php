<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WatchlistItem;
use App\Services\IbkrClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WatchlistController extends Controller
{
    public function __construct(private readonly IbkrClient $client) {}

    public function index(Request $request): View
    {
        return view('watchlist.index', [
            'items' => WatchlistItem::with('latestSnapshot')->orderBy('symbol')->get(),
            'query' => $request->string('symbol')->toString(),
            'results' => $this->search($request->string('symbol')->toString(), $request->string('secType')->toString() ?: 'STK'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'ibkr_con_id' => ['required', 'string', 'unique:watchlist_items,ibkr_con_id'],
            'currency' => ['required', 'string', 'size:3'],
            'market' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        WatchlistItem::create([
            'symbol' => $request->string('symbol')->toString(),
            'ibkr_con_id' => $request->string('ibkr_con_id')->toString(),
            'currency' => $request->string('currency')->toString(),
            'market' => $request->string('market')->toString(),
            'notes' => $request->string('notes')->toString() ?: null,
        ]);

        return redirect()->route('watchlist.index')->with('success', 'Added to the watchlist. It will be priced on the next sync.');
    }

    public function destroy(WatchlistItem $watchlistItem): RedirectResponse
    {
        $watchlistItem->delete();

        return redirect()->route('watchlist.index')->with('success', 'Removed from the watchlist.');
    }

    /**
     * Contract search was already on the client and called from nowhere, which is why finding a
     * conid meant a manual trip through the IBKR interface for every position added by hand.
     *
     * @return array<int, array<string, string>>
     */
    private function search(string $symbol, string $secType): array
    {
        if ($symbol === '') {
            return [];
        }

        try {
            $response = $this->client->searchContracts($symbol, $secType);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        return collect($response->json())
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['conid']))
            ->map(fn (array $row): array => [
                'conid' => $this->text($row['conid']),
                'symbol' => $this->text($row['symbol'] ?? null) ?: $symbol,
                'description' => $this->text($row['companyHeader'] ?? null),
                'exchange' => $this->text($row['description'] ?? null),
            ])
            ->take(15)
            ->values()
            ->all();
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
