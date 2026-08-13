<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sizing model: a fixed cash amount per trigger, in the position currency.
     *
     * A sell is self-sizing because the position says how much is held. A buy is not, and this
     * app has no notion of cash or buying power, so a percentage of it cannot be computed. A
     * share count would age badly as the price moves. A cash amount is the smallest thing that
     * works and stays meaningful, and max_position_value is required alongside it because a
     * buy rule that keeps firing as the price falls is how a small mistake becomes a large one.
     */
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->decimal('buy_below_pct', 5, 2)->nullable()->after('sell_pct');
            $table->decimal('buy_amount', 15, 2)->nullable()->after('buy_below_pct');
            $table->decimal('max_position_value', 15, 2)->nullable()->after('buy_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn(['buy_below_pct', 'buy_amount', 'max_position_value']);
        });
    }
};
