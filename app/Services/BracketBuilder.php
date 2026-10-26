<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Pool;
use App\Models\Tournament;
use Illuminate\Support\Collection;

/**
 * Génère le squelette de la phase finale en double élimination, dès le tirage
 * au sort : les matchs existent avec des places qualificatives ("1er poule A")
 * qui seront remplies à la fin des poules.
 *
 * Fonctionnement par "sources" : une source est soit une place de poule
 * (seed), soit le gagnant / le perdant d'un match. Apparier deux sources crée
 * un match ; une source sans adversaire (exempt) passe telle quelle au tour
 * suivant, sans match fantôme.
 *
 * - Winner bracket : les `wb_per_group` premiers de chaque poule, tableau
 *   classique (puissance de 2, exemptions pour les meilleures têtes de série).
 * - Loser bracket : les `lb_per_group` suivants y entrent directement. Avant
 *   chaque arrivée de perdants du winner bracket, le loser bracket est réduit
 *   au même nombre d'équipes, puis chaque survivant affronte un "descendu".
 * - Grande finale : un seul match, vainqueur WB contre vainqueur LB.
 */
class BracketBuilder
{
    /** @var list<array<string, mixed>> */
    private array $specs = [];

    /** @var array<string, int> compteur de position par stage+round */
    private array $positions = [];

    /**
     * @param  Collection<int, Pool>  $pools  triées par position
     */
    public function build(Tournament $tournament, Collection $pools, int $firstNumber): void
    {
        $this->specs = [];
        $this->positions = [];

        $poolNames = $pools->pluck('name')->values()->all();

        $wbSeeds = $this->seedsFor($poolNames, 1, $tournament->wb_per_group);
        $lbSeeds = $this->seedsFor($poolNames, $tournament->wb_per_group + 1, $tournament->lb_per_group);

        // ---- Winner bracket
        $current = $this->bracketOrder($wbSeeds);
        $dropsByRound = [];
        $round = 0;
        while (count($current) > 1) {
            $round++;
            $next = [];
            $drops = [];
            foreach (array_chunk($current, 2) as [$a, $b]) {
                if ($a && $b) {
                    $index = $this->addSpec(Game::STAGE_WINNER, $round, $a, $b);
                    $next[] = $this->winnerOf($index, $round);
                    $drops[] = $this->loserOf($index);
                } else {
                    $next[] = $a ?? $b;
                    $drops[] = null;
                }
            }
            $dropsByRound[] = $drops;
            $current = $next;
        }
        $wbChampion = $current[0] ?? null;

        // ---- Loser bracket
        $current = $this->bracketOrder($lbSeeds);
        foreach ($dropsByRound as $drops) {
            if ($this->isEmpty($current)) {
                $current = $drops;

                continue;
            }
            while (count($current) > count($drops)) {
                $current = $this->pairLoserRound($current);
            }
            while (count($drops) > count($current)) {
                $drops = $this->pairLoserRound($drops);
            }
            // Croisement : le perdant du dernier match WB affronte le
            // survivant du premier match LB, pour éviter de rejouer trop tôt
            // une affiche déjà vue.
            $drops = array_reverse($drops);
            $merged = [];
            foreach ($current as $i => $source) {
                $merged[] = $source;
                $merged[] = $drops[$i];
            }
            $current = $this->pairLoserRound($merged);
        }
        while (count($current) > 1) {
            $current = $this->pairLoserRound($current);
        }
        $lbChampion = $current[0] ?? null;

        // ---- Grande finale
        if ($wbChampion && $lbChampion) {
            $this->addSpec(Game::STAGE_FINAL, 1, $wbChampion, $lbChampion);
        }

        $this->persist($tournament, $firstNumber);
    }

    /**
     * Places qualificatives triées par niveau : tous les 1ers (A, B, ...),
     * puis tous les 2es, etc.
     *
     * @return list<array<string, mixed>>
     */
    private function seedsFor(array $poolNames, int $fromRank, int $count): array
    {
        $seeds = [];
        for ($rank = $fromRank; $rank < $fromRank + $count; $rank++) {
            foreach ($poolNames as $name) {
                $seeds[] = ['type' => 'seed', 'seed' => "{$name}:{$rank}", 'depth' => 0];
            }
        }

        return $seeds;
    }

    /**
     * Placement classique d'un tableau (1 contre 8, 4 contre 5...) sur la
     * puissance de 2 supérieure ; les cases sans tête de série valent null
     * (exemption). Avec 2 poules : 1A-2B et 1B-2A, comme dans l'exemple.
     *
     * @return list<array<string, mixed>|null>
     */
    private function bracketOrder(array $seeds): array
    {
        $n = count($seeds);
        if ($n === 0) {
            return [];
        }

        $order = [1];
        while (count($order) < $n) {
            $size = count($order) * 2;
            $next = [];
            foreach ($order as $s) {
                $next[] = $s;
                $next[] = $size + 1 - $s;
            }
            $order = $next;
        }

        return array_map(fn ($s) => $seeds[$s - 1] ?? null, $order);
    }

    private function pairLoserRound(array $sources): array
    {
        $next = [];
        foreach (array_chunk($sources, 2) as $pair) {
            [$a, $b] = [$pair[0] ?? null, $pair[1] ?? null];
            if ($a && $b) {
                $round = max($a['depth'], $b['depth']) + 1;
                $index = $this->addSpec(Game::STAGE_LOSER, $round, $a, $b);
                $next[] = $this->winnerOf($index, $round);
            } else {
                $next[] = $a ?? $b;
            }
        }

        return $next;
    }

    private function isEmpty(array $sources): bool
    {
        return count(array_filter($sources)) === 0;
    }

    private function winnerOf(int $index, int $round): array
    {
        return ['type' => 'winner', 'index' => $index, 'depth' => $round];
    }

    private function loserOf(int $index): array
    {
        // Un perdant du WB arrive "neuf" dans le LB : profondeur 0, le
        // numéro de tour LB ne dépend que des matchs déjà joués dans le LB.
        return ['type' => 'loser', 'index' => $index, 'depth' => 0];
    }

    private function addSpec(string $stage, int $round, array $a, array $b): int
    {
        $key = "{$stage}:{$round}";
        $this->positions[$key] = ($this->positions[$key] ?? 0) + 1;

        $index = count($this->specs);
        $this->specs[] = [
            'stage' => $stage,
            'round' => $round,
            'position' => $this->positions[$key],
            'seed_a' => null,
            'seed_b' => null,
            'winner_to' => null,
            'loser_to' => null,
        ];

        foreach (['a' => $a, 'b' => $b] as $slot => $source) {
            match ($source['type']) {
                'seed' => $this->specs[$index]["seed_{$slot}"] = $source['seed'],
                'winner' => $this->specs[$source['index']]['winner_to'] = [$index, $slot],
                'loser' => $this->specs[$source['index']]['loser_to'] = [$index, $slot],
            };
        }

        return $index;
    }

    private function persist(Tournament $tournament, int $firstNumber): void
    {
        // Ordre d'affichage / de numérotation : WB, puis LB, puis finale, tour
        // par tour.
        $stageOrder = [Game::STAGE_WINNER => 0, Game::STAGE_LOSER => 1, Game::STAGE_FINAL => 2];
        $indexes = array_keys($this->specs);
        usort($indexes, fn ($x, $y) => [
            $stageOrder[$this->specs[$x]['stage']], $this->specs[$x]['round'], $this->specs[$x]['position'],
        ] <=> [
            $stageOrder[$this->specs[$y]['stage']], $this->specs[$y]['round'], $this->specs[$y]['position'],
        ]);

        $ids = [];
        $number = $firstNumber;
        foreach ($indexes as $i) {
            $spec = $this->specs[$i];
            $ids[$i] = Game::create([
                'tournament_id' => $tournament->id,
                'number' => $number++,
                'stage' => $spec['stage'],
                'round' => $spec['round'],
                'position' => $spec['position'],
                'seed_a' => $spec['seed_a'],
                'seed_b' => $spec['seed_b'],
            ])->id;
        }

        foreach ($this->specs as $i => $spec) {
            $links = [];
            if ($spec['winner_to']) {
                $links['winner_to_game_id'] = $ids[$spec['winner_to'][0]];
                $links['winner_to_slot'] = $spec['winner_to'][1];
            }
            if ($spec['loser_to']) {
                $links['loser_to_game_id'] = $ids[$spec['loser_to'][0]];
                $links['loser_to_slot'] = $spec['loser_to'][1];
            }
            if ($links) {
                Game::whereKey($ids[$i])->update($links);
            }
        }
    }
}
