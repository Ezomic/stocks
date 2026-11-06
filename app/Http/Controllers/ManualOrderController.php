<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveManualOrderAction;
use App\Http\Requests\StoreManualOrderRequest;
use App\Http\Requests\UpdateManualOrderRequest;
use App\Models\ManualOrder;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManualOrderController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();

        return view('manual-orders.index', [
            'orders' => ManualOrder::with('position')
                ->when(in_array($status, ManualOrder::STATUSES, true), fn ($query) => $query->where('status', $status))
                ->orderByDesc('order_date')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        return view('manual-orders.create', [
            'positions' => Position::orderBy('symbol')->get(),
            'order' => new ManualOrder([
                'position_id' => $request->integer('position_id') ?: null,
                'side' => $request->string('side')->toString() === 'sell' ? 'sell' : 'buy',
                'status' => ManualOrder::STATUS_PLANNED,
                'order_date' => now()->toDateString(),
                'fees' => 0,
                'currency' => 'USD',
            ]),
        ]);
    }

    public function store(StoreManualOrderRequest $request, SaveManualOrderAction $save): RedirectResponse
    {
        $save->handle($request->validated());

        return redirect()->route('manual-orders.index')->with('success', 'Order recorded. Nothing was sent to the broker.');
    }

    public function edit(ManualOrder $manualOrder): View
    {
        return view('manual-orders.edit', [
            'positions' => Position::orderBy('symbol')->get(),
            'order' => $manualOrder,
        ]);
    }

    public function update(UpdateManualOrderRequest $request, ManualOrder $manualOrder, SaveManualOrderAction $save): RedirectResponse
    {
        $save->handle($request->validated(), $manualOrder);

        return redirect()->route('manual-orders.index')->with('success', 'Order updated.');
    }
}
