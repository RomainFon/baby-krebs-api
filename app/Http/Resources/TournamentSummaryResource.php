<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $games = $this->whenLoaded('games', fn () => $this->games, collect());
        $final = $games->firstWhere('stage', Game::STAGE_FINAL);
        $championId = $final?->winnerId();
        $champion = $championId && $this->relationLoaded('teams') ? $this->teams->firstWhere('id', $championId) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'group_count' => $this->group_count,
            'wb_per_group' => $this->wb_per_group,
            'lb_per_group' => $this->lb_per_group,
            'target_score' => $this->target_score,
            'teams_count' => $this->relationLoaded('teams') ? $this->teams->count() : $this->whenCounted('teams'),
            'games_total' => $games->count(),
            'games_played' => $games->filter(fn (Game $g) => $g->isPlayed())->count(),
            // Premier match programmé : sert de "date du tournoi" sur l'accueil.
            'starts_at' => $games->pluck('scheduled_at')->filter()->min()?->toIso8601String(),
            'champion' => $champion ? [
                'id' => $champion->id,
                'name' => $champion->name,
                'short_name' => $champion->shortName(),
                'color' => $champion->color,
                'logo_url' => $champion->logoUrl(),
                'players' => [$champion->playerOne?->name, $champion->playerTwo?->name],
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
