<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The average cost at the moment the order was raised. Captured then rather than
            // read later, because the position average moves and history must not be rewritten
            // by what happened after the trade.
            $table->decimal('cost_basis', 15, 4)->nullable()->after('fill_price');
            $table->char('currency', 3)->nullable()->after('cost_basis');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cost_basis', 'currency']);
        });
    }
};
