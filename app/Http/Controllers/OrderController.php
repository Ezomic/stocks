<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CancelOrderAction;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(): View
    {
        $orders = Order::with('position')->latest()->paginate(50);

        return view('orders.index', compact('orders'));
    }

    public function cancel(Order $order, CancelOrderAction $action): RedirectResponse
    {
        try {
            return back()->with('success', $action->handle($order));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
