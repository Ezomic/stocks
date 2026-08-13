<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An aggregate of its own rather than something derived from price_snapshots on demand:
     * snapshots are pruned after 30 days, so a derived chart would quietly eat its own history.
     */
    public function up(): void
    {
        Schema::create('portfolio_values', function (Blueprint $table) {
            $table->id();
            $table->date('recorded_on');
            $table->char('currency', 3);
            $table->decimal('value', 18, 4);
            $table->decimal('cost', 18, 4);
            $table->unsignedInteger('positions');
            $table->timestamps();

            $table->unique(['recorded_on', 'currency']);
            $table->index('recorded_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_values');
    }
};
