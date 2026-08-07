<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = ['pending', 'placed', 'filled', 'cancelled', 'failed', 'simulated', 'unreconciled'];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', self::STATUSES)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', array_diff(self::STATUSES, ['unreconciled']))
                ->default('pending')
                ->change();
        });
    }
};
