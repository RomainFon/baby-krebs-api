<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlayerRequest;
use App\Models\Game;
use App\Models\GameGoal;
use App\Models\Player;
use App\Services\PlayerStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PlayerController extends Controller
{
    // Public : liste des joueurs avec leurs stats (sert aussi à
    // l'autocomplétion des noms côté admin).
    public function index(PlayerStats $stats): JsonResponse
    {
        $all = $stats->all();

        return response()->json([
            'data' => Player::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Player $p) => ['id' => $p->id, 'name' => $p->name, 'stats' => $all[$p->id]]),
        ]);
    }

    public function show(Player $player, PlayerStats $stats): JsonResponse
    {
        $teams = $stats->teams()->filter(
            fn ($team) => in_array($player->id, $stats->playerIds($team), true),
        );
        $teamIds = $teams->keys()->all();
        $players = Player::query()->pluck('name', 'id');
        $allTeams = $stats->teams();

        $personalGoals = GameGoal::query()->where('player_id', $player->id)->pluck('goals', 'game_id');

        $partnerOf = fn ($team) => $team->player_one_id === $player->id ? $team->player_two_id : $team->player_one_id;

        $games = $stats->games()
            ->filter(fn (Game $g) => in_array($g->team_a_id, $teamIds, true) || in_array($g->team_b_id, $teamIds, true))
            ->sortByDesc(fn (Game $g) => $g->played_at?->timestamp ?? 0)
            ->values()
            ->map(function (Game $g) use ($teamIds, $allTeams, $personalGoals) {
                $isA = in_array($g->team_a_id, $teamIds, true);
                $own = $allTeams->get($isA ? $g->team_a_id : $g->team_b_id);
                $opponent = $allTeams->get($isA ? $g->team_b_id : $g->team_a_id);

                return [
                    'id' => $g->id,
                    'number' => $g->number,
                    'stage' => $g->stage,
                    'tournament_id' => $g->tournament_id,
                    'tournament_name' => $own?->tournament?->name,
                    'team_name' => $own?->name,
                    'opponent_name' => $opponent?->name,
                    'score_for' => $isA ? $g->score_a : $g->score_b,
                    'score_against' => $isA ? $g->score_b : $g->score_a,
                    // Buts du joueur dans ce match, si les buteurs ont été saisis.
                    'goals_scored' => $personalGoals[$g->id] ?? null,
                    'played_at' => $g->played_at?->toIso8601String(),
                ];
            });

        // Bilan par coéquipier : avec qui ça marche le mieux.
        $partners = $teams->groupBy($partnerOf, preserveKeys: true)->map(function ($partnerTeams, $partnerId) use ($stats, $players) {
            $ids = $partnerTeams->keys()->all();
            $played = $stats->games()->filter(fn (Game $g) => in_array($g->team_a_id, $ids, true) || in_array($g->team_b_id, $ids, true));
            $wins = $played->filter(fn (Game $g) => in_array($g->winnerId(), $ids, true))->count();

            return [
                'id' => $partnerId,
                'name' => $players[$partnerId] ?? '?',
                'played' => $played->count(),
                'wins' => $wins,
            ];
        })->sortByDesc('played')->values();

        return response()->json(['data' => [
            'id' => $player->id,
            'name' => $player->name,
            'stats' => $stats->all()[$player->id],
            'teams' => $teams->sortByDesc('tournament_id')->values()->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'tournament_id' => $team->tournament_id,
                'tournament_name' => $team->tournament?->name,
                'tournament_status' => $team->tournament?->status,
                'partner' => ['id' => $partnerOf($team), 'name' => $players[$partnerOf($team)] ?? '?'],
            ]),
            'partners' => $partners,
            'games' => $games,
        ]]);
    }

    public function store(PlayerRequest $request): JsonResponse
    {
        $player = Player::create(['name' => Player::normalize($request->validated('name'))]);

        return response()->json(['data' => ['id' => $player->id, 'name' => $player->name]], 201);
    }

    public function update(PlayerRequest $request, Player $player): JsonResponse
    {
        $player->update(['name' => Player::normalize($request->validated('name'))]);

        return response()->json(['data' => ['id' => $player->id, 'name' => $player->name]]);
    }

    // Un joueur déjà engagé dans une équipe garde son historique : on ne le
    // supprime pas (le renommer reste possible).
    public function destroy(Player $player): Response
    {
        if ($player->isInATeam()) {
            throw ValidationException::withMessages(['player' => "{$player->name} fait partie d'une équipe : impossible de le supprimer."]);
        }
        $player->delete();

        return response()->noContent();
    }
}
