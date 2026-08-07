<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('broker_quantity', 15, 6)->nullable()->after('quantity');
            $table->timestamp('reconciled_at')->nullable()->after('last_triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['broker_quantity', 'reconciled_at']);
        });
    }
};
