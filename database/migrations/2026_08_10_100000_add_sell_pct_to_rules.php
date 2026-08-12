<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            // Percentage of the quantity held at the moment the rule fires, not a share count:
            // the quantity shrinks as a ladder executes, so a fixed count ages badly.
            $table->decimal('sell_pct', 5, 2)->default(100)->after('stop_loss_type');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('remaining_quantity', 15, 6)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('sell_pct');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('remaining_quantity');
        });
    }
};
