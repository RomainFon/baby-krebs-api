<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buteurs par manche (BO3/BO5) : une ligne par joueur et par manche.
        // En BO1, tout est dans la manche 1 (les buteurs existants y vont).
        Schema::table('game_goals', function (Blueprint $table) {
            $table->unsignedTinyInteger('manche')->default(1)->after('player_id');
            $table->dropUnique(['game_id', 'player_id']);
            $table->unique(['game_id', 'manche', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_goals', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'manche', 'player_id']);
            $table->dropColumn('manche');
            $table->unique(['game_id', 'player_id']);
        });
    }
};
