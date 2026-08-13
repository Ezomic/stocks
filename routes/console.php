<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ibkr:sync-prices')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:evaluate-rules')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:sync-orders')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('ibkr:tickle')->everyFifteenMinutes();

// Notifications are queued so a slow mail server cannot stall a scheduled run. There is no
// long-running worker here, only cron, so the queue is drained on the same tick.
Schedule::command('queue:work --stop-when-empty --tries=3')->everyMinute()->withoutOverlapping();

Schedule::command('prices:prune')->dailyAt('02:15');
Schedule::command('ibkr:reconcile-positions')->dailyAt('02:30');

// Recorded before the prune, so a day is never lost to retention.
Schedule::command('portfolio:record')->dailyAt('02:00');
