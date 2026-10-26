<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Pool;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tirage au sort : répartit les équipes dans les poules, génère tous les
 * matchs de poule (chacun contre chacun) et le squelette de la phase finale.
 */
class TournamentDraw
{
    public function __construct(private BracketBuilder $bracket) {}

    /**
     * Vérifie que le format est jouable avec ce nombre d'équipes. Renvoie un
     * message d'erreur, ou null si tout va bien.
     */
    public static function formatError(int $teamCount, int $groupCount, int $wbPerGroup, int $lbPerGroup): ?string
    {
        if ($groupCount * $wbPerGroup < 2) {
            return 'Il faut au moins 2 équipes qualifiées en winner bracket.';
        }
        if ($teamCount < $groupCount * 2) {
            return "Il faut au moins 2 équipes par poule ({$groupCount} poules → ".($groupCount * 2).' équipes minimum).';
        }
        $smallestPool = intdiv($teamCount, $groupCount);
        if ($smallestPool < $wbPerGroup + $lbPerGroup) {
            return "Avec {$teamCount} équipes en {$groupCount} poules, la plus petite poule compte {$smallestPool} équipes : "
                .'pas assez pour qualifier '.($wbPerGroup + $lbPerGroup).' équipes par poule.';
        }

        return null;
    }

    public function run(Tournament $tournament): void
    {
        $teams = $tournament->teams()->get();

        if ($error = self::formatError($teams->count(), $tournament->group_count, $tournament->wb_per_group, $tournament->lb_per_group)) {
            throw ValidationException::withMessages(['teams' => $error]);
        }

        if ($tournament->games()->whereNotNull('score_a')->exists()) {
            throw ValidationException::withMessages(['tournament' => 'Des scores ont déjà été saisis : impossible de refaire le tirage.']);
        }

        DB::transaction(function () use ($tournament, $teams) {
            $tournament->games()->delete();
            $tournament->pools()->delete();

            $pools = collect();
            for ($i = 0; $i < $tournament->group_count; $i++) {
                $pools->push(Pool::create([
                    'tournament_id' => $tournament->id,
                    'name' => chr(ord('A') + $i),
                    'position' => $i,
                ]));
            }

            // Distribution en serpentin après mélange : tailles de poules
            // équilibrées à une équipe près.
            foreach ($teams->shuffle()->values() as $i => $team) {
                $team->update(['pool_id' => $pools[$i % $pools->count()]->id]);
            }

            $number = 1;
            foreach ($pools as $pool) {
                $teamIds = $teams->where('pool_id', $pool->id)->pluck('id')->values()->all();
                foreach ($this->roundRobin($teamIds) as $roundIndex => $pairs) {
                    foreach ($pairs as $position => [$a, $b]) {
                        Game::create([
                            'tournament_id' => $tournament->id,
                            'number' => $number++,
                            'stage' => Game::STAGE_GROUP,
                            'pool_id' => $pool->id,
                            'round' => $roundIndex + 1,
                            'position' => $position + 1,
                            'team_a_id' => $a,
                            'team_b_id' => $b,
                        ]);
                    }
                }
            }

            $this->bracket->build($tournament, $pools, $number);

            $tournament->update(['status' => Tournament::STATUS_GROUPS]);
        });
    }

    /**
     * Méthode du cercle : chaque équipe joue au plus un match par journée.
     *
     * @param  list<int>  $ids
     * @return list<list<array{0:int,1:int}>>
     */
    private function roundRobin(array $ids): array
    {
        if (count($ids) % 2 === 1) {
            $ids[] = null;
        }
        $n = count($ids);
        $rounds = [];
        for ($r = 0; $r < $n - 1; $r++) {
            $pairs = [];
            for ($i = 0; $i < $n / 2; $i++) {
                $a = $ids[$i];
                $b = $ids[$n - 1 - $i];
                if ($a !== null && $b !== null) {
                    $pairs[] = $r % 2 === 0 ? [$a, $b] : [$b, $a];
                }
            }
            $rounds[] = $pairs;
            // Rotation : le premier reste fixe, les autres tournent.
            $ids = array_merge([$ids[0]], [$ids[$n - 1]], array_slice($ids, 1, $n - 2));
        }

        return $rounds;
    }
}
