<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IbkrAuthService;
use Illuminate\Console\Command;

class IbkrTickleCommand extends Command
{
    protected $signature = 'ibkr:tickle';

    protected $description = 'Keep the IBKR gateway session alive';

    public function handle(IbkrAuthService $auth): void
    {
        $auth->tickle();
    }
}
