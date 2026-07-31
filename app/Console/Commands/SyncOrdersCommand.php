<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncOrderStatusAction;
use Illuminate\Console\Command;

class SyncOrdersCommand extends Command
{
    protected $signature = 'ibkr:sync-orders';

    protected $description = 'Sync placed order statuses from IBKR';

    public function handle(SyncOrderStatusAction $action): void
    {
        $action->handle();
    }
}
