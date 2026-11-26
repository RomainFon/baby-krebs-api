<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Format du match (demande du 2026-11-26) : BO1 par défaut, BO3/BO5
        // réglables match par match. En BO3/BO5, `sets` garde les points de
        // chaque manche ([{a, b}, ...]) et score_a/score_b les manches gagnées.
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('best_of')->default(1)->after('team_b_id');
            $table->json('sets')->nullable()->after('score_b');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['best_of', 'sets']);
        });
    }
};
