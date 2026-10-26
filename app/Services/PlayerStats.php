<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * Statistiques par joueur, tous tournois confondus, calculées à la volée
 * depuis les matchs joués (quelques centaines au plus : pas de table dédiée).
 * Un match compte pour les deux joueurs de l'équipe.
 */
class PlayerStats
{
    private ?Collection $teams = null;

    private ?Collection $games = null;

    /** @return Collection<int, Team> */
    public function teams(): Collection
    {
        return $this->teams ??= Team::query()->with('tournament:id,name,status')->get()->keyBy('id');
    }

    /** @return Collection<int, Game> matchs joués */
    public function games(): Collection
    {
        return $this->games ??= Game::query()->whereNotNull('score_a')->whereNotNull('score_b')->orderBy('played_at')->get();
    }

    /** @return list<int> */
    public function playerIds(Team $team): array
    {
        return [$team->player_one_id, $team->player_two_id];
    }

    /**
     * @return array<int, array{played:int, wins:int, losses:int, goals_for:int, goals_against:int, goal_diff:int, win_rate:float, tournaments:int, titles:int, fannys_given:int, fannys_taken:int}>
     */
    public function all(): array
    {
        $empty = fn () => [
            'played' => 0, 'wins' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0,
            'goal_diff' => 0, 'win_rate' => 0.0, 'tournaments' => 0, 'titles' => 0,
            'fannys_given' => 0, 'fannys_taken' => 0,
        ];

        $stats = [];
        foreach (Player::query()->pluck('id') as $id) {
            $stats[$id] = $empty();
        }

        foreach ($this->teams() as $team) {
            foreach ($this->playerIds($team) as $id) {
                $stats[$id] ??= $empty();
                $stats[$id]['tournaments']++;
            }
        }

        foreach ($this->games() as $game) {
            foreach ([[$game->team_a_id, $game->score_a, $game->score_b], [$game->team_b_id, $game->score_b, $game->score_a]] as [$teamId, $for, $against]) {
                $team = $this->teams()->get($teamId);
                if (! $team) {
                    continue;
                }
                foreach ($this->playerIds($team) as $id) {
                    $s = &$stats[$id];
                    $s['played']++;
                    $s['goals_for'] += $for;
                    $s['goals_against'] += $against;
                    $for > $against ? $s['wins']++ : $s['losses']++;
                    // Fanny : défaite (ou victoire) sans marquer un seul but.
                    if ($against === 0) {
                        $s['fannys_given']++;
                    }
                    if ($for === 0) {
                        $s['fannys_taken']++;
                    }
                    if ($game->stage === Game::STAGE_FINAL && $for > $against) {
                        $s['titles']++;
                    }
                    unset($s);
                }
            }
        }

        foreach ($stats as &$s) {
            $s['goal_diff'] = $s['goals_for'] - $s['goals_against'];
            $s['win_rate'] = $s['played'] ? round($s['wins'] / $s['played'], 3) : 0.0;
        }

        return $stats;
    }
}
