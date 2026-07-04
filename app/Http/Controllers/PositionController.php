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
        $positions = Position::with('rule')->get()->map(function (Position $position) {
            $snapshot = PriceSnapshot::latestFor($position->symbol);
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
        $data = $request->validate([
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

        Position::create($data);

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
        $data = $request->validate([
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

        $position->update($data);

        return redirect("/positions/{$position->id}")->with('success', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();

        return redirect('/positions')->with('success', 'Position deleted.');
    }
}
