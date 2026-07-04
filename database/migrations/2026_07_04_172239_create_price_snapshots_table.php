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
        Schema::create('price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->decimal('price', 15, 4);
            $table->char('currency', 3)->default('USD');
            $table->enum('source', ['ibkr', 'cache'])->default('ibkr');
            $table->timestamp('fetched_at');
            $table->index(['symbol', 'fetched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_snapshots');
    }
};
