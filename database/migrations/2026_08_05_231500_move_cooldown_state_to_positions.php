<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->timestamp('last_triggered_at')->nullable()->after('notes');
        });

        // Position-level cooldowns carry over exactly. A global rule's timestamp cannot be
        // attributed to any single position, so it is dropped rather than guessed at.
        DB::table('rules')
            ->whereNotNull('position_id')
            ->whereNotNull('last_triggered_at')
            ->pluck('last_triggered_at', 'position_id')
            ->each(function (mixed $triggeredAt, mixed $positionId): void {
                DB::table('positions')
                    ->where('id', $positionId)
                    ->update(['last_triggered_at' => $triggeredAt]);
            });

        Schema::table('rules', function (Blueprint $table) {
            $table->dropColumn('last_triggered_at');
        });

        Schema::table('rules', function (Blueprint $table) {
            $table->unique('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('rules', function (Blueprint $table) {
            $table->dropUnique(['position_id']);
        });

        Schema::table('rules', function (Blueprint $table) {
            $table->timestamp('last_triggered_at')->nullable()->after('cooldown_minutes');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('last_triggered_at');
        });
    }
};
