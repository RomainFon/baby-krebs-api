<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // draft → groups → bracket → finished, recalculé à chaque score
            // saisi (voir TournamentProgress).
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('group_count');
            // Par poule : les `wb_per_group` premiers vont en winner bracket,
            // les `lb_per_group` suivants en loser bracket, le reste est éliminé.
            $table->unsignedTinyInteger('wb_per_group');
            $table->unsignedTinyInteger('lb_per_group');
            $table->unsignedTinyInteger('target_score')->default(10);
            $table->timestamps();
        });

        // Joueurs globaux (pas rattachés à un tournoi) : base des futures
        // statistiques par joueur, tous tournois confondus.
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // "pools" plutôt que "groups" : mot-clé SQL (window frames).
        Schema::create('pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name', 4);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('player_one_id')->constrained('players');
            $table->foreignId('player_two_id')->constrained('players');
            $table->timestamps();

            $table->unique(['tournament_id', 'name']);
        });

        // "games" plutôt que "matches" : `Match` est un mot réservé en PHP 8.
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('stage'); // group | winner | loser | final
            $table->foreignId('pool_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');
            $table->unsignedSmallInteger('position');
            $table->foreignId('team_a_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('team_b_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->unsignedTinyInteger('score_a')->nullable();
            $table->unsignedTinyInteger('score_b')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('played_at')->nullable();
            // Place qualificative issue des poules ("A:1" = 1er de la poule A),
            // remplie par l'équipe correspondante à la fin des poules.
            $table->string('seed_a', 8)->nullable();
            $table->string('seed_b', 8)->nullable();
            // Où vont le gagnant / le perdant (slot "a" ou "b" du match cible).
            $table->foreignId('winner_to_game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->char('winner_to_slot', 1)->nullable();
            $table->foreignId('loser_to_game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->char('loser_to_slot', 1)->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('pools');
        Schema::dropIfExists('players');
        Schema::dropIfExists('tournaments');
    }
};
