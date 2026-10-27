<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Models\Game;
use App\Services\GameGoals;
use App\Services\TournamentProgress;
use Illuminate\Support\Facades\DB;

class GameController extends Controller
{
    public function update(GameRequest $request, Game $game, TournamentProgress $progress, GameGoals $goals): TournamentDetailResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $game, $progress, $goals) {
            if (array_key_exists('scheduled_at', $data)) {
                $game->update(['scheduled_at' => $data['scheduled_at']]);
            }

            if (array_key_exists('score_a', $data) || array_key_exists('score_b', $data)) {
                $progress->recordScore($game, $data['score_a'] ?? null, $data['score_b'] ?? null);
                // Un score modifié rend l'ancien détail des buteurs faux : il
                // est remplacé par celui envoyé, ou effacé.
                if (! array_key_exists('goals', $data)) {
                    $game->goals()->delete();
                }
            }

            if (array_key_exists('goals', $data)) {
                $goals->sync($game, $data['goals']);
            }
        });

        return new TournamentDetailResource($game->tournament->withDetail());
    }
}
