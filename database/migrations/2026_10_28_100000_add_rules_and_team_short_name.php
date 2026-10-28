<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Règlement du tournoi, texte libre saisi par l'admin.
        Schema::table('tournaments', function (Blueprint $table) {
            $table->text('rules')->nullable()->after('target_score');
        });

        // Diminutif de l'équipe ("CQP") ; vide = initiales du nom.
        Schema::table('teams', function (Blueprint $table) {
            $table->string('short_name', 5)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn('rules'));
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('short_name'));
    }
};
