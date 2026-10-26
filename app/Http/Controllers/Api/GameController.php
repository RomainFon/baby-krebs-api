<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Models\Game;
use App\Services\TournamentProgress;

class GameController extends Controller
{
    public function update(GameRequest $request, Game $game, TournamentProgress $progress): TournamentDetailResource
    {
        $data = $request->validated();

        if (array_key_exists('scheduled_at', $data)) {
            $game->update(['scheduled_at' => $data['scheduled_at']]);
        }

        if (array_key_exists('score_a', $data) || array_key_exists('score_b', $data)) {
            $progress->recordScore($game, $data['score_a'] ?? null, $data['score_b'] ?? null);
        }

        return new TournamentDetailResource(
            $game->tournament->fresh()->load(['teams.playerOne', 'teams.playerTwo', 'pools', 'games']),
        );
    }
}
