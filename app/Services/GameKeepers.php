<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * Gardien de chaque équipe dans un match. Facultatif, par équipe : le
 * gardien doit être un des deux joueurs de son équipe.
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
        if ($set && ! $game->isPlayed()) {
            throw ValidationException::withMessages(['keepers' => 'Saisis d\'abord le score du match.']);
        }

        foreach (['keeper_a_id' => $game->team_a_id, 'keeper_b_id' => $game->team_b_id] as $key => $teamId) {
            if (isset($set[$key]) && ! in_array((int) $set[$key], Team::find($teamId)?->playerIds() ?? [], true)) {
                throw ValidationException::withMessages([$key => 'Le gardien doit être un joueur de son équipe.']);
            }
        }

        $game->update(array_map(fn ($id) => $id === null ? null : (int) $id, $data));
    }
}
