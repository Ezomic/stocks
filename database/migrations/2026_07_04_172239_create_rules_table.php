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
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('take_profit_pct', 5, 2)->nullable();
            $table->decimal('stop_loss_pct', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
