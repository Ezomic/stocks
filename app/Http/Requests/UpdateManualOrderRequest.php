<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ManualOrder;

class UpdateManualOrderRequest extends StoreManualOrderRequest
{
    protected function ignoredOrderId(): ?int
    {
        $order = $this->route('manualOrder');

        return $order instanceof ManualOrder ? $order->id : null;
    }
}
