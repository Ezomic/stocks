<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneSnapshotsCommand extends Command
{
    protected $signature = 'prices:prune {--days= : Override the configured retention window}';

    protected $description = 'Delete price snapshots older than the retention window';

    public function handle(): void
    {
        $days = $this->retentionDays();

        if ($days <= 0) {
            $this->info('Retention is disabled, nothing pruned.');

            return;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = PriceSnapshot::where('fetched_at', '<', $cutoff)->count();

        if ($deleted > 0) {
            PriceSnapshot::where('fetched_at', '<', $cutoff)->delete();
        }

        $this->info("Pruned {$deleted} price snapshots older than {$days} days.");
    }

    private function retentionDays(): int
    {
        $override = $this->option('days');

        if (is_numeric($override)) {
            return (int) $override;
        }

        return PriceSnapshot::retentionDays();
    }
}
