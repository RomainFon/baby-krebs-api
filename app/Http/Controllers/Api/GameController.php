<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Models\Game;
use App\Services\GameGoals;
use App\Services\GameKeepers;
use App\Services\TournamentProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameController extends Controller
{
    public function update(GameRequest $request, Game $game, TournamentProgress $progress, GameGoals $goals, GameKeepers $keepers): TournamentDetailResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $game, $progress, $goals, $keepers) {
            if (array_key_exists('scheduled_at', $data)) {
                $game->update(['scheduled_at' => $data['scheduled_at']]);
            }

            if (array_key_exists('best_of', $data) && $data['best_of'] !== $game->best_of) {
                // Changer de format sur un match joué rendrait son score faux.
                if ($game->isStarted() && ! $this->clearsScore($data)) {
                    throw ValidationException::withMessages(['best_of' => 'Efface d\'abord le score pour changer le format du match.']);
                }
                $game->update(['best_of' => $data['best_of']]);
            }

            $scoreTouched = array_key_exists('score_a', $data) || array_key_exists('score_b', $data) || array_key_exists('sets', $data);
            if ($scoreTouched) {
                if ($game->best_of > 1 && ! $this->clearsScore($data) && ! array_key_exists('sets', $data)) {
                    throw ValidationException::withMessages(['sets' => "Match en BO{$game->best_of} : saisis le score de chaque manche."]);
                }
                if ($game->best_of === 1 && ! empty($data['sets'])) {
                    throw ValidationException::withMessages(['sets' => 'Match en BO1 : saisis directement le score.']);
                }
                $progress->recordScore($game, $data['score_a'] ?? null, $data['score_b'] ?? null, $data['sets'] ?? null);
                // Un score modifié rend l'ancien détail des buteurs faux : il
                // est remplacé par celui envoyé, ou effacé.
                if (! array_key_exists('goals', $data)) {
                    $game->goals()->delete();
                }
            }

            if (array_key_exists('goals', $data)) {
                $goals->sync($game, $data['goals']);
            }

            $keeperData = array_intersect_key($data, array_flip(['keeper_a_id', 'keeper_b_id']));
            if ($keeperData) {
                $keepers->sync($game, $keeperData);
            }

            // Le gardien de chaque équipe est obligatoire dès qu'on saisit un
            // score ou qu'on touche aux gardiens (son coéquipier = attaquant).
            // Les matchs déjà joués sans gardien restent lisibles tels quels.
            if (($scoreTouched || $keeperData) && $game->refresh()->isStarted()) {
                $keepers->ensureComplete($game);
            }
        });

        return new TournamentDetailResource($game->tournament->withDetail());
    }

    /** La requête efface le score (scores à null et aucune manche). */
    private function clearsScore(array $data): bool
    {
        $keys = array_intersect_key($data, array_flip(['score_a', 'score_b', 'sets']));

        return $keys !== [] && array_filter($keys, fn ($v) => $v !== null && $v !== []) === [];
    }
}
