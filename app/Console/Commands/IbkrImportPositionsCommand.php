<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ImportPositionsFromIbkrAction;
use Illuminate\Console\Command;

class IbkrImportPositionsCommand extends Command
{
    protected $signature = 'ibkr:import-positions';

    protected $description = 'Import current positions from IBKR into the local database';

    public function handle(ImportPositionsFromIbkrAction $action): void
    {
        $count = $action->handle();
        $this->info("Imported or updated {$count} positions.");
    }
}
