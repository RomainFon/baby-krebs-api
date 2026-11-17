<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gardien de chaque équipe dans un match (facultatif) : sert à la stat
        // « buts encaissés » par joueur. Un seul gardien par équipe et par
        // match, même si les joueurs ont changé de poste en cours de partie.
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('keeper_a_id')->nullable()->after('score_b')->constrained('players')->nullOnDelete();
            $table->foreignId('keeper_b_id')->nullable()->after('keeper_a_id')->constrained('players')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('keeper_a_id');
            $table->dropConstrainedForeignId('keeper_b_id');
        });
    }
};
