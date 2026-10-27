<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * Détail des buteurs d'un match. Facultatif, par équipe : si les buts d'une
 * équipe sont renseignés, leur total doit égaler son score.
 */
class GameGoals
{
    /**
     * @param  array<int, array{player_id:int, goals:int}>  $entries
     */
    public function sync(Game $game, array $entries): void
    {
        $game->refresh();

        if (! $game->isPlayed()) {
            throw ValidationException::withMessages(['goals' => 'Saisis d\'abord le score du match.']);
        }

        $sides = [
            'a' => [Team::find($game->team_a_id), $game->score_a],
            'b' => [Team::find($game->team_b_id), $game->score_b],
        ];

        $bySide = ['a' => [], 'b' => []];
        foreach ($entries as $entry) {
            $side = collect($sides)->search(fn ($s) => in_array((int) $entry['player_id'], $s[0]->playerIds(), true));
            if ($side === false) {
                throw ValidationException::withMessages(['goals' => 'Un des joueurs ne participe pas à ce match.']);
            }
            $bySide[$side][(int) $entry['player_id']] = (int) $entry['goals'];
        }

        foreach ($bySide as $side => $goals) {
            [$team, $score] = $sides[$side];
            if ($goals && array_sum($goals) !== $score) {
                throw ValidationException::withMessages([
                    'goals' => "Les buts de {$team->name} (".array_sum($goals).") ne correspondent pas à son score ({$score}).",
                ]);
            }
        }

        $game->goals()->delete();
        foreach ($bySide as $goals) {
            foreach ($goals as $playerId => $count) {
                $game->goals()->create(['player_id' => $playerId, 'goals' => $count]);
            }
        }
    }
}
