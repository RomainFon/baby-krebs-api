<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Identifiant du compte Microsoft rattaché à la première connexion
            // SSO (le rattachement se fait par l'email, voir
            // MicrosoftAuthController).
            $table->string('microsoft_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('microsoft_id');
        });
    }
};
