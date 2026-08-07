<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcilePositionsAction;
use Illuminate\Console\Command;

class ReconcilePositionsCommand extends Command
{
    protected $signature = 'ibkr:reconcile-positions';

    protected $description = 'Compare local position quantities with the broker and report any drift';

    public function handle(ReconcilePositionsAction $action): void
    {
        ['reconciled' => $reconciled, 'drifted' => $drifted, 'unknown' => $unknown] = $action->handle();

        $this->info("Reconciled {$reconciled} positions, {$drifted} differ from the broker.");

        if ($unknown > 0) {
            $this->warn("{$unknown} positions have no conid and could not be checked.");
        }
    }
}
