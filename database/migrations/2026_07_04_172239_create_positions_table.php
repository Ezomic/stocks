<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('broker_account_id');
            $table->enum('account_mode', ['paper', 'live'])->default('paper');
            $table->decimal('quantity', 15, 6);
            $table->decimal('avg_buy_price', 15, 4);
            $table->char('currency', 3)->default('USD');
            $table->string('market')->default('STK');
            $table->string('ibkr_con_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
