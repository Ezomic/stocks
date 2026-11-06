<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ManualOrder;
use App\Models\Position;

class SaveManualOrderAction
{
    /**
     * Records the order only. A linked position lends its symbol and currency so the log
     * cannot drift from the holding; it is never changed by what is logged here.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?ManualOrder $order = null): ManualOrder
    {
        $positionId = $data['position_id'] ?? null;
        $position = is_numeric($positionId) ? Position::find((int) $positionId) : null;

        if ($position !== null) {
            $data['symbol'] = $position->symbol;
            $data['currency'] = $position->currency;
        }

        if ($order === null) {
            return ManualOrder::create($data);
        }

        $order->update($data);

        return $order;
    }
}
