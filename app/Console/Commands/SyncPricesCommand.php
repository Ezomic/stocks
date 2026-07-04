<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncPricesAction;
use Illuminate\Console\Command;

class SyncPricesCommand extends Command
{
    protected $signature = 'ibkr:sync-prices';

    protected $description = 'Fetch latest prices from IBKR and store as price snapshots';

    public function handle(SyncPricesAction $action): void
    {
        $action->handle();
    }
}
