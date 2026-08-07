<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('symbol')->nullable()->after('position_id');
        });

        // The order log has to read correctly on its own once the position is gone.
        DB::table('orders')->orderBy('id')->chunkById(500, function ($orders): void {
            foreach ($orders as $order) {
                $symbol = DB::table('positions')->where('id', $order->position_id)->value('symbol');

                DB::table('orders')->where('id', $order->id)->update(['symbol' => $symbol]);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('orders')->whereNull('position_id')->delete();

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('position_id')->references('id')->on('positions')->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('symbol');
        });
    }
};
