<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Couleur de l'écusson par défaut (#rrggbb), tirée au hasard à la
            // création ; logo optionnel stocké sur le disque local.
            $table->string('color', 7)->nullable()->after('name');
            $table->string('logo_path')->nullable()->after('color');
        });

        // Buts marqués par chaque joueur dans un match (facultatif : le score
        // de l'équipe reste la référence, ce détail l'affine).
        Schema::create('game_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('goals');
            $table->timestamps();

            $table->unique(['game_id', 'player_id']);
        });

        // Couleurs pour les équipes déjà existantes, tournoi par tournoi.
        DB::table('teams')->orderBy('id')->get()->groupBy('tournament_id')->each(function ($teams) {
            foreach ($teams->values() as $i => $team) {
                DB::table('teams')->where('id', $team->id)
                    ->update(['color' => Team::COLORS[$i % count(Team::COLORS)]]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_goals');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['color', 'logo_path']);
        });
    }
};
