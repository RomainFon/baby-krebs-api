<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TournamentRequest;
use App\Http\Resources\TournamentDetailResource;
use App\Http\Resources\TournamentSummaryResource;
use App\Models\Tournament;
use App\Services\TournamentDraw;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TournamentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TournamentSummaryResource::collection(
            Tournament::query()->with(['games', 'teams.playerOne', 'teams.playerTwo'])->orderByDesc('id')->get(),
        );
    }

    public function show(Tournament $tournament): TournamentDetailResource
    {
        return new TournamentDetailResource($this->loadDetail($tournament));
    }

    public function store(TournamentRequest $request): TournamentDetailResource
    {
        $tournament = Tournament::create($request->validated());

        return new TournamentDetailResource($this->loadDetail($tournament));
    }

    public function update(TournamentRequest $request, Tournament $tournament): TournamentDetailResource
    {
        $data = $request->validated();

        $formatFields = ['group_count', 'wb_per_group', 'lb_per_group', 'target_score'];
        $changesFormat = collect($formatFields)->contains(
            fn ($field) => array_key_exists($field, $data) && (int) $data[$field] !== $tournament->{$field},
        );
        if ($changesFormat && ! $tournament->isDraft()) {
            throw ValidationException::withMessages([
                'group_count' => 'Le format ne peut plus être modifié après le tirage au sort.',
            ]);
        }

        $tournament->update($data);

        return new TournamentDetailResource($this->loadDetail($tournament));
    }

    public function destroy(Tournament $tournament): Response
    {
        $tournament->delete();

        return response()->noContent();
    }

    public function draw(Tournament $tournament, TournamentDraw $draw): TournamentDetailResource
    {
        if (! in_array($tournament->status, [Tournament::STATUS_DRAFT, Tournament::STATUS_GROUPS], true)) {
            throw ValidationException::withMessages(['tournament' => 'Les poules sont terminées : impossible de refaire le tirage.']);
        }

        $draw->run($tournament);

        return new TournamentDetailResource($this->loadDetail($tournament));
    }

    // Annule le tirage (retour en brouillon) pour pouvoir modifier les
    // équipes ou le format. Refusé dès qu'un score existe.
    public function resetDraw(Tournament $tournament): TournamentDetailResource
    {
        if ($tournament->games()->whereNotNull('score_a')->exists()) {
            throw ValidationException::withMessages(['tournament' => 'Des scores ont déjà été saisis : impossible d\'annuler le tirage.']);
        }

        $tournament->games()->delete();
        $tournament->pools()->delete();
        $tournament->update(['status' => Tournament::STATUS_DRAFT]);

        return new TournamentDetailResource($this->loadDetail($tournament));
    }

    private function loadDetail(Tournament $tournament): Tournament
    {
        return $tournament->fresh()->load(['teams.playerOne', 'teams.playerTwo', 'pools', 'games']);
    }
}
