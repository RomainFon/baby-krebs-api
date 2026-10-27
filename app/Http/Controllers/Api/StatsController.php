<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Services\PlayerStats;
use Illuminate\Http\JsonResponse;

/**
 * Chiffres "pour rire" de la page d'accueil, tous tournois confondus.
 */
class StatsController extends Controller
{
    // En dessous, un 1/1 ferait de n'importe qui le "meilleur joueur".
    private const MIN_GAMES_FOR_RANKING = 3;

    public function index(PlayerStats $stats): JsonResponse
    {
        $games = $stats->games();
        $names = Player::query()->pluck('name', 'id');
        $all = collect($stats->all())->map(fn ($s, $id) => ['id' => $id, 'name' => $names[$id] ?? '?', ...$s]);

        $ranked = $all->where('played', '>=', self::MIN_GAMES_FOR_RANKING);
        if ($ranked->isEmpty()) {
            $ranked = $all->where('played', '>', 0);
        }

        $player = fn ($row) => $row ? ['id' => $row['id'], 'name' => $row['name'], 'stats' => collect($row)->except(['id', 'name'])] : null;
        $byRate = fn ($row) => [$row['win_rate'], $row['goal_diff'], $row['played']];

        return response()->json(['data' => [
            'games_played' => $games->count(),
            'best_player' => $player($ranked->sortByDesc($byRate)->first()),
            'worst_player' => $player($ranked->sortBy($byRate)->first()),
            // Vrai meilleur buteur dès que des buteurs ont été saisis, sinon le
            // joueur de l'équipe la plus prolifique.
            'top_scorer' => $player(
                $all->where('goals_scored', '>', 0)->sortByDesc('goals_scored')->first()
                ?? $all->where('played', '>', 0)->sortByDesc('goals_for')->first(),
            ),
            'leakiest' => $player($all->where('played', '>', 0)->sortByDesc(fn ($r) => $r['goals_against'] / $r['played'])->first()),
            'most_titles' => $player($all->where('titles', '>', 0)->sortByDesc('titles')->first()),
            'fanny_victim' => $player($all->where('fannys_taken', '>', 0)->sortByDesc('fannys_taken')->first()),
            'min_games_for_ranking' => self::MIN_GAMES_FOR_RANKING,
        ]]);
    }
}
