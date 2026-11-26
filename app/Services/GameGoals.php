<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * Détail des buteurs d'un match, manche par manche (une seule manche en BO1).
 * Facultatif, par équipe et par manche : si les buts d'une équipe sont
 * renseignés pour une manche, leur total doit égaler ses points dans cette
 * manche.
 */
class GameGoals
{
    /**
     * @param  array<int, array{player_id:int, goals:int, manche?:int}>  $entries
     */
    public function sync(Game $game, array $entries): void
    {
        $game->refresh();

        $manches = $game->manches();
        if (! $manches) {
            throw ValidationException::withMessages(['goals' => 'Saisis d\'abord le score du match.']);
        }
        $teams = ['a' => Team::find($game->team_a_id), 'b' => Team::find($game->team_b_id)];
        $multi = $game->best_of > 1;

        // [manche][side][player_id] => buts
        $byManche = [];
        foreach ($entries as $entry) {
            $manche = (int) ($entry['manche'] ?? 1);
            $playerId = (int) $entry['player_id'];
            if (! isset($manches[$manche - 1])) {
                throw ValidationException::withMessages(['goals' => "La manche {$manche} n'a pas été jouée."]);
            }
            $side = collect($teams)->search(fn (Team $t) => in_array($playerId, $t->playerIds(), true));
            if ($side === false) {
                throw ValidationException::withMessages(['goals' => 'Un des joueurs ne participe pas à ce match.']);
            }
            if (isset($byManche[$manche][$side][$playerId])) {
                throw ValidationException::withMessages(['goals' => 'Un joueur apparaît deux fois dans la même manche.']);
            }
            $byManche[$manche][$side][$playerId] = (int) $entry['goals'];
        }

        foreach ($byManche as $manche => $sides) {
            foreach ($sides as $side => $goals) {
                $score = $manches[$manche - 1][$side];
                if (array_sum($goals) !== $score) {
                    $where = $multi ? " en manche {$manche}" : '';
                    throw ValidationException::withMessages([
                        'goals' => "Les buts de {$teams[$side]->name}{$where} (".array_sum($goals).") ne correspondent pas à son score ({$score}).",
                    ]);
                }
            }
        }

        $game->goals()->delete();
        foreach ($byManche as $manche => $sides) {
            foreach ($sides as $goals) {
                foreach ($goals as $playerId => $count) {
                    $game->goals()->create(['player_id' => $playerId, 'manche' => $manche, 'goals' => $count]);
                }
            }
        }
    }
}
