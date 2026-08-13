<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('ibkr_con_id');
            $table->char('currency', 3)->default('USD');
            $table->string('market')->default('STK');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('ibkr_con_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');
    }
};
