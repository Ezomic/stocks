<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\PriceSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(): View
    {
        $positions = Position::with(['rule', 'latestSnapshot'])->get()->map(function (Position $position) {
            $snapshot = $position->latestSnapshot;
            $position->current_price = $snapshot ? (float) $snapshot->price : null;
            $position->gain_pct = $snapshot ? $position->gainPct((float) $snapshot->price) : null;

            return $position;
        });

        return view('positions.index', compact('positions'));
    }

    public function create(): View
    {
        return view('positions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'broker_account_id' => ['required', 'string'],
            'account_mode' => ['required', 'in:paper,live'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'avg_buy_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'market' => ['required', 'string'],
            'ibkr_con_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        Position::create($this->positionAttributes($request));

        return redirect('/positions')->with('success', 'Position added.');
    }

    public function show(Position $position): View
    {
        $snapshots = PriceSnapshot::where('symbol', $position->symbol)
            ->orderByDesc('fetched_at')
            ->limit(288)
            ->get()
            ->reverse()
            ->values();

        $orders = $position->orders()->with('rule')->latest()->get();
        $currentPrice = $snapshots->last()?->price;
        $gainPct = $currentPrice ? $position->gainPct((float) $currentPrice) : null;

        return view('positions.show', compact('position', 'snapshots', 'orders', 'currentPrice', 'gainPct'));
    }

    public function edit(Position $position): View
    {
        return view('positions.edit', compact('position'));
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'broker_account_id' => ['required', 'string'],
            'account_mode' => ['required', 'in:paper,live'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'avg_buy_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'market' => ['required', 'string'],
            'ibkr_con_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $position->update($this->positionAttributes($request));

        return redirect("/positions/{$position->id}")->with('success', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();

        return redirect('/positions')->with('success', 'Position deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function positionAttributes(Request $request): array
    {
        return [
            'symbol' => $request->string('symbol')->toString(),
            'broker_account_id' => $request->string('broker_account_id')->toString(),
            'account_mode' => $request->string('account_mode')->toString(),
            'quantity' => $request->float('quantity'),
            'avg_buy_price' => $request->float('avg_buy_price'),
            'currency' => $request->string('currency')->toString(),
            'market' => $request->string('market')->toString(),
            'ibkr_con_id' => $request->string('ibkr_con_id')->toString() ?: null,
            'notes' => $request->string('notes')->toString() ?: null,
        ];
    }
}
