<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecordPortfolioValueAction;
use Illuminate\Console\Command;

class RecordPortfolioValueCommand extends Command
{
    protected $signature = 'portfolio:record';

    protected $description = 'Record the portfolio value per currency for today';

    public function handle(RecordPortfolioValueAction $action): void
    {
        $currencies = $action->handle();

        $this->info("Recorded the portfolio value for {$currencies} ".($currencies === 1 ? 'currency' : 'currencies').'.');
    }
}
