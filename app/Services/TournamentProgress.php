<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Team;
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
    /**
     * @param  list<array{a:int, b:int}>|null  $sets  points de chaque manche, en BO3/BO5
     */
    public function recordScore(Game $game, ?int $scoreA, ?int $scoreB, ?array $sets = null): void
    {
        $tournament = $game->tournament;
        $multi = $game->best_of > 1;
        $clearing = $multi ? ! $sets && $scoreA === null && $scoreB === null : $scoreA === null && $scoreB === null;

        if (! $clearing) {
            if ($multi) {
                // Le résultat (manches gagnées) se déduit des manches saisies.
                [$scoreA, $scoreB, $sets] = $this->validateSets($tournament, $game, $sets ?? []);
            } else {
                $this->validateScore($tournament, $game, $scoreA, $scoreB);
                $sets = null;
            }
        } else {
            $sets = null;
        }

        $this->ensureEditable($tournament, $game);

        DB::transaction(function () use ($game, $tournament, $scoreA, $scoreB, $sets, $clearing) {
            if ($clearing) {
                $game->goals()->delete();
            }

            $game->update([
                // BO3/BO5 pas encore décidé : manches enregistrées, pas de résultat.
                'score_a' => $scoreA,
                'score_b' => $scoreB,
                'sets' => $sets,
                // Pas de gardien sur un match qui n'est plus joué.
                ...($clearing ? ['keeper_a_id' => null, 'keeper_b_id' => null] : []),
                'played_at' => $scoreA === null ? null : ($game->played_at ?? now()),
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

    /**
     * BO3/BO5 : chaque manche se joue en `target_score` points, on s'arrête
     * dès qu'une équipe a gagné la majorité des manches. Les manches se
     * saisissent au fur et à mesure : tant que personne n'a la majorité, le
     * résultat reste vide (null, null).
     *
     * Chaque manche porte aussi son gardien de chaque côté (vérifié ici,
     * obligatoire : voir GameKeepers::ensureComplete).
     *
     * @return array{0:?int, 1:?int, 2:list<array{a:int, b:int, keeper_a_id:?int, keeper_b_id:?int}>}
     */
    private function validateSets(Tournament $tournament, Game $game, array $sets): array
    {
        if ($game->team_a_id === null || $game->team_b_id === null) {
            throw ValidationException::withMessages(['score' => 'Les deux équipes de ce match ne sont pas encore connues.']);
        }
        $toWin = $game->setsToWin();
        $target = $tournament->target_score;
        $players = [
            'a' => Team::find($game->team_a_id)->playerIds(),
            'b' => Team::find($game->team_b_id)->playerIds(),
        ];
        $won = ['a' => 0, 'b' => 0];
        $clean = [];
        foreach (array_values($sets) as $i => $set) {
            $n = $i + 1;
            if (max($won) === $toWin) {
                throw ValidationException::withMessages(['sets' => "Le match est déjà gagné avant la manche {$n}."]);
            }
            $a = $set['a'] ?? null;
            $b = $set['b'] ?? null;
            if ($a === null || $b === null) {
                throw ValidationException::withMessages(['sets' => "Manche {$n} : il faut saisir les deux scores."]);
            }
            [$a, $b] = [(int) $a, (int) $b];
            if (max($a, $b) !== $target || min($a, $b) >= $target) {
                throw ValidationException::withMessages(['sets' => "Manche {$n} : une équipe doit avoir {$target}, l'autre moins."]);
            }
            $keepers = [];
            foreach (['a', 'b'] as $side) {
                $id = $set["keeper_{$side}_id"] ?? null;
                if ($id !== null && ! in_array((int) $id, $players[$side], true)) {
                    throw ValidationException::withMessages(['sets' => "Manche {$n} : le gardien doit être un joueur de son équipe."]);
                }
                $keepers["keeper_{$side}_id"] = $id === null ? null : (int) $id;
            }
            $won[$a > $b ? 'a' : 'b']++;
            $clean[] = ['a' => $a, 'b' => $b, ...$keepers];
        }
        if (max($won) !== $toWin) {
            return [null, null, $clean];
        }

        return [$won['a'], $won['b'], $clean];
    }

    private function ensureEditable(Tournament $tournament, Game $game): void
    {
        if (! $game->isBracket()) {
            $bracketStarted = $tournament->games()->where('stage', '!=', Game::STAGE_GROUP)->where(Game::startedConstraint())->exists();
            if ($bracketStarted) {
                throw ValidationException::withMessages(['score' => 'La phase finale a commencé : les scores de poule sont verrouillés.']);
            }

            return;
        }

        $downstream = Game::whereIn('id', array_filter([$game->winner_to_game_id, $game->loser_to_game_id]))
            ->where(Game::startedConstraint())
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
        $bracketStarted = $games->where('stage', '!=', Game::STAGE_GROUP)->contains(fn (Game $g) => $g->isStarted());

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
