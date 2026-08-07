<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Both scheduled commands filter on status every minute, and EvaluateRulesAction
            // then plucks position_id off the result.
            $table->index('status');
            $table->index('position_id');
        });

        Schema::table('price_snapshots', function (Blueprint $table) {
            // The existing index leads on symbol, which the retention sweep does not filter by.
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['position_id']);
        });

        Schema::table('price_snapshots', function (Blueprint $table) {
            $table->dropIndex(['fetched_at']);
        });
    }
};
