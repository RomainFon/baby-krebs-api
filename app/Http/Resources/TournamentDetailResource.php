<?php

namespace App\Http\Resources;

use App\Models\Game;
use App\Models\Pool;
use App\Models\Team;
use App\Services\Standings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tout le tournoi en une réponse (quelques dizaines de matchs au plus) : le
 * client calcule lui-même les vues (arbre, fiches équipes) à partir de là.
 */
class TournamentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $teams = $this->teams->sortBy('name')->values();
        $names = $teams->pluck('name', 'id');
        $games = $this->games;

        return [
            ...(new TournamentSummaryResource($this->resource))->toArray($request),
            'teams' => $teams->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'pool_id' => $team->pool_id,
                'players' => [
                    ['id' => $team->playerOne->id, 'name' => $team->playerOne->name],
                    ['id' => $team->playerTwo->id, 'name' => $team->playerTwo->name],
                ],
            ]),
            'pools' => $this->pools->map(fn (Pool $pool) => [
                'id' => $pool->id,
                'name' => $pool->name,
                'standings' => Standings::compute(
                    $teams->where('pool_id', $pool->id)->pluck('id')->values(),
                    $games->where('pool_id', $pool->id),
                    $names,
                ),
            ]),
            'games' => $games->map(fn (Game $game) => [
                'id' => $game->id,
                'number' => $game->number,
                'stage' => $game->stage,
                'pool_id' => $game->pool_id,
                'round' => $game->round,
                'position' => $game->position,
                'team_a_id' => $game->team_a_id,
                'team_b_id' => $game->team_b_id,
                'score_a' => $game->score_a,
                'score_b' => $game->score_b,
                'scheduled_at' => $game->scheduled_at?->toIso8601String(),
                'played_at' => $game->played_at?->toIso8601String(),
                'seed_a' => $game->seed_a,
                'seed_b' => $game->seed_b,
                'winner_to_game_id' => $game->winner_to_game_id,
                'winner_to_slot' => $game->winner_to_slot,
                'loser_to_game_id' => $game->loser_to_game_id,
                'loser_to_slot' => $game->loser_to_slot,
            ])->values(),
        ];
    }
}
