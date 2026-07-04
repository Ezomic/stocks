<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EvaluateRulesAction;
use Illuminate\Console\Command;

class EvaluateRulesCommand extends Command
{
    protected $signature = 'ibkr:evaluate-rules';

    protected $description = 'Evaluate take-profit and stop-loss rules against current prices';

    public function handle(EvaluateRulesAction $action): void
    {
        $action->handle();
    }
}
