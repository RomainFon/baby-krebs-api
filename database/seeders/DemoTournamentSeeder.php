<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\TournamentDraw;
use App\Services\TournamentProgress;
use Illuminate\Database\Seeder;

/**
 * Trois tournois de démo avec les mêmes joueurs (pour des stats joueurs
 * parlantes) :
 * - un tournoi terminé l'été dernier ;
 * - le tournoi en cours, calqué sur l'exemple HTML (8 équipes, 2 poules de 4,
 *   2 premiers en WB, 3e et 4e en LB), poules et demi-finales WB jouées ;
 * - un tournoi à venir, encore en inscriptions.
 */
class DemoTournamentSeeder extends Seeder
{
    public function run(): void
    {
        $summer = $this->tournament("Tournoi d'été", [
            'Les Pissettes' => ['Alex', 'Chloé'], 'Gamelles' => ['Basile', 'David'],
            'Demi-Volées' => ['Emma', 'Gaspard'], 'Roulettes' => ['Farid', 'Hugo'],
            'Les Murs' => ['Inès', 'Karim'], 'Pichenettes' => ['Jules', 'Léa'],
        ], groups: 2, wb: 2, lb: 1);
        $this->play($summer, now()->subMonths(4), until: null);

        $current = $this->tournament('Tournoi de la Toussaint', [
            'Titans' => ['Alex', 'Basile'], 'Comètes' => ['Chloé', 'David'],
            'Lynx' => ['Emma', 'Farid'], 'Orcas' => ['Gaspard', 'Hugo'],
            'Faucons' => ['Inès', 'Jules'], 'Dragons' => ['Karim', 'Léa'],
            'Nova' => ['Manon', 'Noé'], 'Vipères' => ['Oscar', 'Pauline'],
        ], groups: 2, wb: 2, lb: 2);
        $this->play($current, now()->startOfDay()->setTime(12, 0), until: fn (Game $g) => $g->stage === Game::STAGE_GROUP
            || ($g->stage === Game::STAGE_WINNER && $g->round === 1));

        $this->tournament('Tournoi de Noël', [
            'Rennes' => ['Alex', 'Manon'], 'Sapins' => ['Basile', 'Noé'],
            'Lutins' => ['Chloé', 'Oscar'],
        ], groups: 2, wb: 2, lb: 1, draw: false);
    }

    private function tournament(string $name, array $teams, int $groups, int $wb, int $lb, bool $draw = true): Tournament
    {
        $tournament = Tournament::create([
            'name' => $name, 'group_count' => $groups, 'wb_per_group' => $wb, 'lb_per_group' => $lb,
        ]);
        foreach ($teams as $teamName => [$one, $two]) {
            $tournament->teams()->create([
                'name' => $teamName,
                'color' => Team::randomColor($tournament),
                'player_one_id' => Player::findOrCreateByName($one)->id,
                'player_two_id' => Player::findOrCreateByName($two)->id,
            ]);
        }
        if ($draw) {
            app(TournamentDraw::class)->run($tournament);
        }

        return $tournament;
    }

    /**
     * Programme tous les matchs à 15 min d'intervalle, puis joue (score
     * aléatoire) tant que `$until` l'accepte — tous les matchs si null.
     */
    private function play(Tournament $tournament, $start, ?callable $until): void
    {
        foreach ($tournament->games()->get() as $i => $game) {
            $game->update(['scheduled_at' => $start->copy()->addMinutes(15 * $i)]);
        }

        $progress = app(TournamentProgress::class);
        while ($game = $tournament->games()->whereNull('score_a')->whereNotNull('team_a_id')->whereNotNull('team_b_id')->first()) {
            if ($until && ! $until($game)) {
                return;
            }
            $loser = random_int(0, 9);
            $progress->recordScore($game, ...(random_int(0, 1) ? [10, $loser] : [$loser, 10]));
            $game->update(['played_at' => $game->scheduled_at]);
        }
    }
}
