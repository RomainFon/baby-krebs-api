<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un match est programmé à un jour, plus à une heure (retours du 2026-11-23).
 * Les dates existantes sont en UTC : on garde le jour vu depuis Paris.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE games ALTER COLUMN scheduled_at TYPE date USING (scheduled_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Paris')::date");

            return;
        }

        Schema::table('games', fn ($table) => $table->date('scheduled_at')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE games ALTER COLUMN scheduled_at TYPE timestamp(0) without time zone USING scheduled_at::timestamp');

            return;
        }

        Schema::table('games', fn ($table) => $table->dateTime('scheduled_at')->nullable()->change());
    }
};
