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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('side', ['buy', 'sell']);
            $table->decimal('quantity', 15, 6);
            $table->enum('order_type', ['market', 'limit'])->default('market');
            $table->decimal('limit_price', 15, 4)->nullable();
            $table->enum('status', ['pending', 'placed', 'filled', 'cancelled', 'failed'])->default('pending');
            $table->string('broker_order_id')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->decimal('fill_price', 15, 4)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
