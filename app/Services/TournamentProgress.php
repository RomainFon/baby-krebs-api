<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saisie d'un score et tout ce qui en découle : avancement du gagnant et du
 * perdant dans l'arbre, qualification des équipes à la fin des poules, statut
 * du tournoi.
 */
class TournamentProgress
{
    public function recordScore(Game $game, ?int $scoreA, ?int $scoreB): void
    {
        $tournament = $game->tournament;
        $clearing = $scoreA === null && $scoreB === null;

        if (! $clearing) {
            $this->validateScore($tournament, $game, $scoreA, $scoreB);
        }

        $this->ensureEditable($tournament, $game);

        DB::transaction(function () use ($game, $tournament, $scoreA, $scoreB, $clearing) {
            if ($clearing) {
                $game->goals()->delete();
            }

            $game->update([
                'score_a' => $scoreA,
                'score_b' => $scoreB,
                'played_at' => $clearing ? null : ($game->played_at ?? now()),
            ]);

            if ($game->isBracket()) {
                $this->propagate($game);
            }

            $this->refresh($tournament);
        });
    }

    private function validateScore(Tournament $tournament, Game $game, ?int $scoreA, ?int $scoreB): void
    {
        if ($game->team_a_id === null || $game->team_b_id === null) {
            throw ValidationException::withMessages(['score' => 'Les deux équipes de ce match ne sont pas encore connues.']);
        }
        if ($scoreA === null || $scoreB === null) {
            throw ValidationException::withMessages(['score' => 'Il faut saisir les deux scores.']);
        }
        $target = $tournament->target_score;
        if (max($scoreA, $scoreB) !== $target || min($scoreA, $scoreB) >= $target) {
            throw ValidationException::withMessages(['score' => "Un match se joue en {$target} points : une équipe doit avoir {$target}, l'autre moins."]);
        }
    }

    private function ensureEditable(Tournament $tournament, Game $game): void
    {
        if (! $game->isBracket()) {
            $bracketStarted = $tournament->games()->where('stage', '!=', Game::STAGE_GROUP)->whereNotNull('score_a')->exists();
            if ($bracketStarted) {
                throw ValidationException::withMessages(['score' => 'La phase finale a commencé : les scores de poule sont verrouillés.']);
            }

            return;
        }

        $downstream = Game::whereIn('id', array_filter([$game->winner_to_game_id, $game->loser_to_game_id]))
            ->whereNotNull('score_a')
            ->exists();
        if ($downstream) {
            throw ValidationException::withMessages(['score' => 'Le match suivant a déjà été joué : corrige-le d\'abord.']);
        }
    }

    private function propagate(Game $game): void
    {
        foreach ([
            [$game->winner_to_game_id, $game->winner_to_slot, $game->winnerId()],
            [$game->loser_to_game_id, $game->loser_to_slot, $game->loserId()],
        ] as [$targetId, $slot, $teamId]) {
            if ($targetId) {
                Game::whereKey($targetId)->update(["team_{$slot}_id" => $teamId]);
            }
        }
    }

    /**
     * Remplit les places qualificatives quand toutes les poules sont finies
     * (ou les vide si un score de poule a été effacé), puis recalcule le
     * statut.
     */
    public function refresh(Tournament $tournament): void
    {
        $tournament->refresh();
        if ($tournament->isDraft()) {
            return;
        }

        $games = $tournament->games()->get();
        $groupGames = $games->where('stage', Game::STAGE_GROUP);
        $groupsDone = $groupGames->every(fn (Game $g) => $g->isPlayed());
        $bracketStarted = $games->where('stage', '!=', Game::STAGE_GROUP)->contains(fn (Game $g) => $g->isPlayed());

        if (! $bracketStarted) {
            $seedMap = $groupsDone ? $this->seedMap($tournament, $groupGames) : [];
            foreach ($games->where('stage', '!=', Game::STAGE_GROUP) as $game) {
                $changes = [];
                foreach (['a', 'b'] as $slot) {
                    if ($seed = $game->{"seed_{$slot}"}) {
                        $changes["team_{$slot}_id"] = $seedMap[$seed] ?? null;
                    }
                }
                if ($changes) {
                    $game->update($changes);
                }
            }
        }

        $final = $games->firstWhere('stage', Game::STAGE_FINAL);
        $status = match (true) {
            $final?->fresh()->isPlayed() => Tournament::STATUS_FINISHED,
            $groupsDone => Tournament::STATUS_BRACKET,
            default => Tournament::STATUS_GROUPS,
        };
        $tournament->update(['status' => $status]);
    }

    /** @return array<string, int> "A:1" => team_id */
    private function seedMap(Tournament $tournament, $groupGames): array
    {
        $teams = $tournament->teams()->get();
        $names = $teams->pluck('name', 'id');
        $map = [];
        foreach ($tournament->pools()->get() as $pool) {
            $rows = Standings::compute(
                $teams->where('pool_id', $pool->id)->pluck('id')->values(),
                $groupGames->where('pool_id', $pool->id),
                $names,
            );
            foreach ($rows as $row) {
                $map["{$pool->name}:{$row['rank']}"] = $row['team_id'];
            }
        }

        return $map;
    }
}
