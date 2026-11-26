<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameGoal;
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
     * @return array<int, array{played:int, wins:int, losses:int, goals_for:int, goals_against:int, goal_diff:int, win_rate:float, tournaments:int, titles:int, fannys_given:int, fannys_taken:int, goals_scored:int, games_with_goals:int, games_as_keeper:int, goals_conceded_as_keeper:int, wins_as_keeper:int, keeper_win_rate:float, games_as_attacker:int, wins_as_attacker:int, attacker_win_rate:float, goals_scored_as_attacker:int, games_with_goals_as_attacker:int, goals_scored_as_keeper:int, games_with_goals_as_keeper:int}>
     */
    public function all(): array
    {
        $empty = fn () => [
            'played' => 0, 'wins' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0,
            'goal_diff' => 0, 'win_rate' => 0.0, 'tournaments' => 0, 'titles' => 0,
            'fannys_given' => 0, 'fannys_taken' => 0,
            // Buts marqués par le joueur lui-même (matchs où les buteurs ont
            // été saisis) ; goals_for reste le total de son équipe.
            'goals_scored' => 0, 'games_with_goals' => 0,
            // Matchs où le joueur a été désigné gardien, et buts encaissés
            // dans ces matchs ; goals_against reste le total de son équipe.
            'games_as_keeper' => 0, 'goals_conceded_as_keeper' => 0,
            // Postes déduits du gardien saisi : gardien = défenseur, son
            // coéquipier = attaquant. Matchs sans gardien : aucun poste.
            'wins_as_keeper' => 0, 'keeper_win_rate' => 0.0,
            'games_as_attacker' => 0, 'wins_as_attacker' => 0, 'attacker_win_rate' => 0.0,
            'goals_scored_as_attacker' => 0, 'games_with_goals_as_attacker' => 0,
            'goals_scored_as_keeper' => 0, 'games_with_goals_as_keeper' => 0,
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
            // Buts = points de toutes les manches (BO3/BO5), victoire = celle
            // du match, fanny = manche perdue sans marquer.
            $manches = $game->manches();
            $wonA = $game->winnerId() === $game->team_a_id;
            foreach ([
                [$game->team_a_id, 'a', 'b', $wonA],
                [$game->team_b_id, 'b', 'a', ! $wonA],
            ] as [$teamId, $own, $other, $won]) {
                $for = $game->points($own);
                $against = $game->points($other);
                $fannysGiven = count(array_filter($manches, fn ($m) => $m[$other] === 0));
                $fannysTaken = count(array_filter($manches, fn ($m) => $m[$own] === 0));
                $team = $this->teams()->get($teamId);
                if (! $team) {
                    continue;
                }
                foreach ($this->playerIds($team) as $id) {
                    $s = &$stats[$id];
                    $s['played']++;
                    $s['goals_for'] += $for;
                    $s['goals_against'] += $against;
                    $won ? $s['wins']++ : $s['losses']++;
                    // Fanny : manche perdue (ou gagnée) sans marquer un seul but.
                    $s['fannys_given'] += $fannysGiven;
                    $s['fannys_taken'] += $fannysTaken;
                    if ($game->stage === Game::STAGE_FINAL && $won) {
                        $s['titles']++;
                    }
                    // Postes manche par manche (le gardien peut changer en
                    // BO3/BO5 ; en BO1, une manche = le match) : gardien =
                    // défenseur, son coéquipier = attaquant, aucun poste si
                    // le gardien n'a pas été saisi.
                    foreach ($manches as $m) {
                        $keeperId = $m["keeper_{$own}_id"];
                        $mancheWon = $m[$own] > $m[$other];
                        if ($keeperId === $id) {
                            $s['games_as_keeper']++;
                            $s['goals_conceded_as_keeper'] += $m[$other];
                            if ($mancheWon) {
                                $s['wins_as_keeper']++;
                            }
                        } elseif ($keeperId !== null) {
                            $s['games_as_attacker']++;
                            if ($mancheWon) {
                                $s['wins_as_attacker']++;
                            }
                        }
                    }
                    unset($s);
                }
            }
        }

        $games = $this->games()->keyBy('id');
        $goalRows = GameGoal::query()->whereIn('game_id', $games->keys())->get();
        // Matchs avec buts saisis : un par match, même sur plusieurs manches.
        foreach ($goalRows->unique(fn (GameGoal $g) => "{$g->game_id}:{$g->player_id}") as $goal) {
            $stats[$goal->player_id] ??= $empty();
            $stats[$goal->player_id]['games_with_goals']++;
        }
        foreach ($goalRows as $goal) {
            $s = &$stats[$goal->player_id];
            $s['goals_scored'] += $goal->goals;
            // Poste dans cette manche : gardien saisi = défenseur, sinon
            // attaquant si son équipe a un gardien saisi.
            $game = $games->get($goal->game_id);
            $inTeamA = in_array($goal->player_id, $this->playerIds($this->teams()->get($game->team_a_id) ?? new Team), true);
            $keeperId = $game->manches()[$goal->manche - 1][$inTeamA ? 'keeper_a_id' : 'keeper_b_id'] ?? null;
            if ($keeperId === $goal->player_id) {
                $s['goals_scored_as_keeper'] += $goal->goals;
                $s['games_with_goals_as_keeper']++;
            } elseif ($keeperId !== null) {
                $s['goals_scored_as_attacker'] += $goal->goals;
                $s['games_with_goals_as_attacker']++;
            }
            unset($s);
        }

        foreach ($stats as &$s) {
            $s['goal_diff'] = $s['goals_for'] - $s['goals_against'];
            $s['win_rate'] = $s['played'] ? round($s['wins'] / $s['played'], 3) : 0.0;
            $s['keeper_win_rate'] = $s['games_as_keeper'] ? round($s['wins_as_keeper'] / $s['games_as_keeper'], 3) : 0.0;
            $s['attacker_win_rate'] = $s['games_as_attacker'] ? round($s['wins_as_attacker'] / $s['games_as_attacker'], 3) : 0.0;
        }
        unset($s);

        return $stats;
    }
}
