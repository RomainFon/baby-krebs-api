<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Collection;

/**
 * Classement d'une poule : victoires, puis différence de buts, puis buts
 * marqués, puis confrontations directes entre les équipes encore à égalité,
 * et enfin ordre alphabétique (dernier recours, ne devrait jamais servir).
 */
class Standings
{
    /**
     * @param  Collection<int, int>  $teamIds
     * @param  Collection<int, Game>  $games  matchs de la poule
     * @return list<array{team_id:int, rank:int, played:int, wins:int, losses:int, goals_for:int, goals_against:int, goal_diff:int}>
     */
    public static function compute(Collection $teamIds, Collection $games, Collection $teamNames): array
    {
        $rows = [];
        foreach ($teamIds as $id) {
            $rows[$id] = [
                'team_id' => $id, 'rank' => 0, 'played' => 0, 'wins' => 0, 'losses' => 0,
                'goals_for' => 0, 'goals_against' => 0, 'goal_diff' => 0,
            ];
        }

        $played = $games->filter(fn (Game $g) => $g->isPlayed());

        foreach ($played as $game) {
            foreach ([[$game->team_a_id, $game->score_a, $game->score_b], [$game->team_b_id, $game->score_b, $game->score_a]] as [$id, $for, $against]) {
                if (! isset($rows[$id])) {
                    continue;
                }
                $rows[$id]['played']++;
                $rows[$id]['goals_for'] += $for;
                $rows[$id]['goals_against'] += $against;
                $rows[$id]['goal_diff'] += $for - $against;
                $for > $against ? $rows[$id]['wins']++ : $rows[$id]['losses']++;
            }
        }

        // Regroupe les équipes à égalité parfaite sur (V, diff, buts marqués)
        // puis départage chaque paquet par les confrontations directes.
        $buckets = collect($rows)
            ->groupBy(fn ($r) => sprintf('%05d|%05d|%05d', $r['wins'], $r['goal_diff'] + 10000, $r['goals_for']))
            ->sortKeysDesc();

        $ordered = [];
        foreach ($buckets as $bucket) {
            $ids = $bucket->pluck('team_id')->all();
            $h2hWins = array_fill_keys($ids, 0);
            foreach ($played as $game) {
                if (in_array($game->team_a_id, $ids, true) && in_array($game->team_b_id, $ids, true)) {
                    $h2hWins[$game->winnerId()]++;
                }
            }
            usort($ids, fn ($a, $b) => [$h2hWins[$b], $teamNames[$a] ?? ''] <=> [$h2hWins[$a], $teamNames[$b] ?? '']);
            foreach ($ids as $id) {
                $ordered[] = $rows[$id];
            }
        }

        foreach ($ordered as $i => &$row) {
            $row['rank'] = $i + 1;
        }

        return $ordered;
    }
}
