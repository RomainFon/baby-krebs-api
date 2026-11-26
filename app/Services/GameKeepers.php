<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * Gardien de chaque équipe dans un match : un des deux joueurs de son
 * équipe, son coéquipier étant l'attaquant. Obligatoire sur un match joué
 * (vérifié par ensureComplete, appelé à la saisie).
 */
class GameKeepers
{
    /**
     * @param  array{keeper_a_id?: ?int, keeper_b_id?: ?int}  $data  seules les clés présentes sont modifiées
     */
    public function sync(Game $game, array $data): void
    {
        $game->refresh();

        $set = array_filter($data, fn ($id) => $id !== null);
        if ($set && $game->best_of > 1) {
            throw ValidationException::withMessages(['keepers' => "Match en BO{$game->best_of} : le gardien se saisit dans chaque manche."]);
        }
        if ($set && ! $game->isStarted()) {
            throw ValidationException::withMessages(['keepers' => 'Saisis d\'abord le score du match.']);
        }

        foreach (['keeper_a_id' => $game->team_a_id, 'keeper_b_id' => $game->team_b_id] as $key => $teamId) {
            if (isset($set[$key]) && ! in_array((int) $set[$key], Team::find($teamId)?->playerIds() ?? [], true)) {
                throw ValidationException::withMessages([$key => 'Le gardien doit être un joueur de son équipe.']);
            }
        }

        $game->update(array_map(fn ($id) => $id === null ? null : (int) $id, $data));
    }

    /**
     * Un match joué doit avoir le gardien de chaque équipe ; en BO3/BO5,
     * chaque manche saisie.
     */
    public function ensureComplete(Game $game): void
    {
        if ($game->best_of > 1) {
            foreach ($game->manches() as $i => $manche) {
                if ($manche['keeper_a_id'] === null || $manche['keeper_b_id'] === null) {
                    throw ValidationException::withMessages(['sets' => 'Manche '.($i + 1).' : indique le gardien de chaque équipe.']);
                }
            }

            return;
        }

        $missing = array_keys(array_filter(
            ['keeper_a_id' => $game->keeper_a_id, 'keeper_b_id' => $game->keeper_b_id],
            fn ($id) => $id === null,
        ));
        if ($missing) {
            throw ValidationException::withMessages(array_fill_keys($missing, 'Indique le gardien de chaque équipe.'));
        }
    }
}
