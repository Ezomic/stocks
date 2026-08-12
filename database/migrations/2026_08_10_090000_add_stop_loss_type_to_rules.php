<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->enum('stop_loss_type', ['entry', 'trailing'])->default('entry')->after('stop_loss_pct');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('stop_loss_type');
        });
    }
};
