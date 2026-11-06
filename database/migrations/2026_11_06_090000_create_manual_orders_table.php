<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('side');
            $table->decimal('quantity', 18, 6);
            $table->decimal('price', 18, 4)->nullable();
            $table->decimal('fees', 18, 4)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->date('order_date');
            $table->text('note')->nullable();
            $table->string('status')->default('planned');
            $table->timestamps();

            $table->index(['status', 'order_date']);
            $table->index(['position_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_orders');
    }
};
