<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeamRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $tournament->teams()->create([
            'color' => Team::randomColor($tournament),
            ...$this->attributes($request),
        ]);

        return $this->detail($tournament);
    }

    // Renommer une équipe, corriger un joueur ou changer sa couleur reste
    // possible à tout moment (aucun impact sur l'arbre).
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

        $team->logo_path && Storage::disk('local')->delete($team->logo_path);
        $team->delete();

        return $this->detail($tournament);
    }

    public function uploadLogo(Request $request, Team $team): TournamentDetailResource
    {
        // Pas de SVG : il serait servi tel quel et pourrait embarquer du script.
        $request->validate(
            ['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']],
            ['logo.max' => 'Le logo ne doit pas dépasser 2 Mo.', 'logo.mimes' => 'Formats acceptés : PNG, JPG, WEBP.'],
        );

        $previous = $team->logo_path;
        $team->update(['logo_path' => $request->file('logo')->store('team-logos', 'local')]);
        $previous && Storage::disk('local')->delete($previous);

        return $this->detail($team->tournament);
    }

    public function deleteLogo(Team $team): TournamentDetailResource
    {
        $team->logo_path && Storage::disk('local')->delete($team->logo_path);
        $team->update(['logo_path' => null]);

        return $this->detail($team->tournament);
    }

    // Public : servi par l'API (sous /api) pour ne rien changer au routage
    // nginx/Traefik. L'URL porte un `?v=` qui change à chaque logo : cache long.
    public function showLogo(Team $team): StreamedResponse
    {
        abort_unless($team->logo_path && Storage::disk('local')->exists($team->logo_path), 404);

        return Storage::disk('local')->response($team->logo_path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function attributes(TeamRequest $request): array
    {
        [$one, $two] = $request->validated('players');

        return [
            'name' => trim($request->validated('name')),
            'player_one_id' => $one,
            'player_two_id' => $two,
            ...($request->has('color') ? ['color' => strtolower($request->validated('color'))] : []),
        ];
    }

    private function detail(Tournament $tournament): TournamentDetailResource
    {
        return new TournamentDetailResource(
            $tournament->withDetail(),
        );
    }
}
