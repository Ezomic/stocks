<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        DB::statement('PRAGMA journal_mode=WAL;');
        DB::statement('PRAGMA synchronous=NORMAL;');
    }
}
