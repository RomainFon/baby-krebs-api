<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeamRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Validation\ValidationException;

/**
 * Les réponses renvoient le tournoi complet : le client remplace simplement
 * son état, pas de fusion à gérer.
 */
class TeamController extends Controller
{
    public function store(TeamRequest $request, Tournament $tournament): TournamentDetailResource
    {
        if (! $tournament->isDraft()) {
            throw ValidationException::withMessages(['name' => 'Le tirage a eu lieu : annule-le pour ajouter une équipe.']);
        }

        $tournament->teams()->create($this->attributes($request));

        return $this->detail($tournament);
    }

    // Renommer une équipe ou corriger un nom de joueur reste possible à tout
    // moment (aucun impact sur l'arbre).
    public function update(TeamRequest $request, Team $team): TournamentDetailResource
    {
        $team->update($this->attributes($request));

        return $this->detail($team->tournament);
    }

    public function destroy(Team $team): TournamentDetailResource
    {
        $tournament = $team->tournament;
        if (! $tournament->isDraft()) {
            throw ValidationException::withMessages(['team' => 'Le tirage a eu lieu : annule-le pour supprimer une équipe.']);
        }

        $team->delete();

        return $this->detail($tournament);
    }

    private function attributes(TeamRequest $request): array
    {
        [$one, $two] = $request->validated('players');

        return [
            'name' => trim($request->validated('name')),
            'player_one_id' => $one,
            'player_two_id' => $two,
        ];
    }

    private function detail(Tournament $tournament): TournamentDetailResource
    {
        return new TournamentDetailResource(
            $tournament->fresh()->load(['teams.playerOne', 'teams.playerTwo', 'pools', 'games']),
        );
    }
}
