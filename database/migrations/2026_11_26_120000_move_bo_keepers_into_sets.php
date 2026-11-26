<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * BO3/BO5 : le gardien peut changer à chaque manche, il est donc rangé
     * dans chaque manche de `sets` (keeper_a_id / keeper_b_id) et plus dans
     * les colonnes du match (qui restent celles des BO1).
     */
    public function up(): void
    {
        DB::table('games')->where('best_of', '>', 1)->whereNotNull('sets')->orderBy('id')->each(function ($game) {
            $sets = array_map(fn ($s) => [
                'a' => $s['a'], 'b' => $s['b'],
                'keeper_a_id' => $s['keeper_a_id'] ?? $game->keeper_a_id,
                'keeper_b_id' => $s['keeper_b_id'] ?? $game->keeper_b_id,
            ], json_decode($game->sets, true) ?? []);
            DB::table('games')->where('id', $game->id)->update([
                'sets' => json_encode($sets), 'keeper_a_id' => null, 'keeper_b_id' => null,
            ]);
        });
    }

    public function down(): void
    {
        // Rien : les gardiens par manche restent lisibles.
    }
};
