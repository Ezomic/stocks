<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ibkr:sync-prices')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:evaluate-rules')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:sync-orders')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('ibkr:tickle')->everyFifteenMinutes();
