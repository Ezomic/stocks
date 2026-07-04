<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        DB::statement('PRAGMA journal_mode=WAL;');
        DB::statement('PRAGMA synchronous=NORMAL;');
    }
}
