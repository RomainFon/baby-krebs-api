<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TournamentDraw;
use App\Services\TournamentProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TournamentFlowTest extends TestCase
{
    use RefreshDatabase;

    public static function formats(): array
    {
        // [équipes, poules, qualifiés WB par poule, qualifiés LB par poule]
        return [
            'exemple 8 équipes' => [8, 2, 2, 2],
            '10 équipes, 2 poules de 5' => [10, 2, 2, 2],
            '9 équipes, 3 poules (WB non puissance de 2)' => [9, 3, 1, 1],
            '11 équipes, 3 poules, 2+1' => [11, 3, 2, 1],
            'pas de qualifiés directs en LB' => [8, 2, 2, 0],
            '12 équipes, 4 poules, 1+2' => [12, 4, 1, 2],
            'une seule poule' => [6, 1, 4, 2],
            'finale WB directe' => [4, 2, 1, 1],
        ];
    }

    #[DataProvider('formats')]
    public function test_a_full_tournament_can_be_played(int $teamCount, int $groups, int $wb, int $lb): void
    {
        $tournament = $this->makeTournament($teamCount, $groups, $wb, $lb);
        app(TournamentDraw::class)->run($tournament);

        $groupGames = $tournament->games()->where('stage', Game::STAGE_GROUP)->count();
        $expected = $tournament->pools()->get()->sum(function ($pool) {
            $n = $pool->teams()->count();

            return $n * ($n - 1) / 2;
        });
        $this->assertSame((int) $expected, $groupGames);

        $this->playEverything($tournament);

        $tournament->refresh();
        $this->assertSame(Tournament::STATUS_FINISHED, $tournament->status);

        $bracket = $tournament->games()->where('stage', '!=', Game::STAGE_GROUP)->get();
        $this->assertTrue($bracket->every(fn (Game $g) => $g->isPlayed()), 'Tous les matchs de phase finale ont été joués');

        // Double élimination : chaque qualifié WB perd au plus 2 fois,
        // chaque qualifié LB au plus 1 fois, et tous sauf le champion sont
        // éliminés.
        $losses = [];
        foreach ($bracket as $game) {
            $losses[$game->loserId()] = ($losses[$game->loserId()] ?? 0) + 1;
        }
        $wbTeams = [];
        $lbTeams = [];
        foreach ($bracket as $game) {
            foreach (['a', 'b'] as $slot) {
                if ($seed = $game->{"seed_{$slot}"}) {
                    $rank = (int) explode(':', $seed)[1];
                    $rank <= $wb ? $wbTeams[] = $game->{"team_{$slot}_id"} : $lbTeams[] = $game->{"team_{$slot}_id"};
                }
            }
        }
        $this->assertCount($groups * $wb, $wbTeams);
        $this->assertCount($groups * $lb, $lbTeams);

        $final = $bracket->firstWhere('stage', Game::STAGE_FINAL);
        $champion = $final->winnerId();
        // Le champion peut venir du LB : il a alors déjà perdu une fois.
        $this->assertLessThanOrEqual(in_array($champion, $wbTeams, true) ? 1 : 0, $losses[$champion] ?? 0);

        foreach (array_merge($wbTeams, $lbTeams) as $team) {
            if ($team === $champion) {
                continue;
            }
            $maxLosses = in_array($team, $wbTeams, true) ? 2 : 1;
            $eliminated = ($losses[$team] ?? 0) === $maxLosses || $team === $final->loserId();
            $this->assertTrue($eliminated, "L'équipe {$team} a fini le tournoi sans être éliminée");
            $this->assertLessThanOrEqual($maxLosses, $losses[$team]);
        }
    }

    public function test_scores_must_reach_the_target(): void
    {
        $tournament = $this->makeTournament(4, 1, 2, 0);
        app(TournamentDraw::class)->run($tournament);
        $game = $tournament->games()->first();
        $admin = User::factory()->create();

        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['score_a' => 9, 'score_b' => 8])
            ->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['score_a' => 10, 'score_b' => 10])
            ->assertUnprocessable();
        $keepers = [
            'keeper_a_id' => Team::find($game->team_a_id)->player_one_id,
            'keeper_b_id' => Team::find($game->team_b_id)->player_one_id,
        ];
        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['score_a' => 10, 'score_b' => 7])
            ->assertUnprocessable()->assertJsonValidationErrors(['keeper_a_id', 'keeper_b_id']);
        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['score_a' => 10, 'score_b' => 7, ...$keepers])
            ->assertOk()
            ->assertJsonPath('data.games.0.score_a', 10);
    }

    public function test_a_game_can_be_played_in_best_of_three(): void
    {
        $tournament = $this->makeTournament(4, 1, 2, 0);
        app(TournamentDraw::class)->run($tournament);
        $game = $tournament->games()->first();
        $admin = User::factory()->create();
        $a = $game->team_a_id;
        [$p1, $p2] = [Team::find($a)->player_one_id, Team::find($a)->player_two_id];
        [$q1, $q2] = [Team::find($game->team_b_id)->player_one_id, Team::find($game->team_b_id)->player_two_id];
        // Une manche : points et gardien de chaque équipe (il peut changer).
        $m = fn ($sa, $sb, $ka = null, $kb = null) => ['a' => $sa, 'b' => $sb, 'keeper_a_id' => $ka ?? $p1, 'keeper_b_id' => $kb ?? $q1];
        $url = "/api/games/{$game->id}";

        $this->actingAs($admin)->patchJson($url, ['best_of' => 2])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, ['best_of' => 3])->assertOk()
            ->assertJsonPath('data.games.0.best_of', 3);

        // Un score simple ne suffit plus, il faut les manches.
        $this->actingAs($admin)->patchJson($url, ['score_a' => 10, 'score_b' => 7])
            ->assertUnprocessable()->assertJsonValidationErrors('sets');
        // Gardiens au niveau du match : refusés, ils se saisissent par manche.
        $this->actingAs($admin)->patchJson($url, ['sets' => [$m(10, 7)], 'keeper_a_id' => $p1, 'keeper_b_id' => $q1])
            ->assertUnprocessable()->assertJsonValidationErrors('keepers');
        // Gardien manquant ou pas de la bonne équipe.
        $this->actingAs($admin)->patchJson($url, ['sets' => [['a' => 10, 'b' => 7, 'keeper_a_id' => $p1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sets');
        $this->actingAs($admin)->patchJson($url, ['sets' => [$m(10, 7, $q1)]])
            ->assertUnprocessable()->assertJsonValidationErrors('sets');

        // Saisie au fur et à mesure : une manche, puis la suivante. Tant que
        // personne n'a 2 manches, pas de résultat ni d'avancement.
        $this->actingAs($admin)->patchJson($url, ['sets' => [$m(10, 7)]])->assertOk()
            ->assertJsonPath('data.games.0.score_a', null)
            ->assertJsonPath('data.games.0.played_at', null)
            ->assertJsonPath('data.games.0.sets.0.keeper_a_id', $p1);
        $this->actingAs($admin)->patchJson($url, ['sets' => [$m(10, 7), $m(4, 10, $p2)]])->assertOk()
            ->assertJsonPath('data.games.0.score_a', null)
            ->assertJsonPath('data.games.0.sets.1.keeper_a_id', $p2);
        $this->assertSame(0, $this->getJson("/api/tournaments/{$tournament->id}")->json('data.games_played'));
        // Commencé : le format ne change plus.
        $this->actingAs($admin)->patchJson($url, ['best_of' => 5])->assertUnprocessable();
        // Manche de trop après 2-0, manche invalide.
        $this->actingAs($admin)->patchJson($url, ['sets' => [$m(10, 7), $m(10, 2), $m(3, 10)]])
            ->assertUnprocessable()->assertJsonValidationErrors('sets');
        $this->actingAs($admin)->patchJson($url, ['sets' => [$m(10, 7), $m(9, 8)]])
            ->assertUnprocessable()->assertJsonValidationErrors('sets');

        // A gagne 2-1 en marquant moins de points au total (20 contre 27).
        // p1 garde la manche 1 (perdue), puis p2 garde les deux suivantes.
        $sets = ['sets' => [$m(0, 10), $m(10, 8, $p2), $m(10, 9, $p2, $q2)]];
        // Buteurs groupés sur le match : refusés, ils se saisissent par manche.
        $this->actingAs($admin)->patchJson($url, [...$sets, 'goals' => [
            ['player_id' => $p1, 'goals' => 20], ['player_id' => $p2, 'goals' => 0],
        ]])->assertUnprocessable()->assertJsonValidationErrors('goals');
        $this->actingAs($admin)->patchJson($url, [...$sets, 'goals' => [
            ['player_id' => $p1, 'manche' => 2, 'goals' => 6], ['player_id' => $p2, 'manche' => 2, 'goals' => 4],
            ['player_id' => $p1, 'manche' => 3, 'goals' => 10], ['player_id' => $p2, 'manche' => 3, 'goals' => 0],
        ]])->assertOk()
            ->assertJsonPath('data.games.0.goals.0.manche', 2)
            ->assertJsonPath('data.games.0.score_a', 2)
            ->assertJsonPath('data.games.0.score_b', 1)
            ->assertJsonPath('data.games.0.sets.2.b', 9);

        $row = collect($this->getJson("/api/tournaments/{$tournament->id}")->json('data.pools.0.standings'))->firstWhere('team_id', $a);
        $this->assertSame([1, 20, 27], [$row['wins'], $row['goals_for'], $row['goals_against']]);
        // La manche perdue 0-10 compte comme une fanny. Postes manche par
        // manche : p1 gardien en M1 (perdue, 10 encaissés), attaquant en M2
        // et M3 (gagnées, 16 buts).
        $stats = $this->getJson("/api/players/{$p1}")->json('data.stats');
        $this->assertSame([1, 1, 16, 1], [$stats['wins'], $stats['fannys_taken'], $stats['goals_scored'], $stats['games_with_goals']]);
        $this->assertSame([1, 0, 10], [$stats['games_as_keeper'], $stats['wins_as_keeper'], $stats['goals_conceded_as_keeper']]);
        $this->assertSame([2, 2, 16], [$stats['games_as_attacker'], $stats['wins_as_attacker'], $stats['goals_scored_as_attacker']]);
        $this->assertSame([2, 4], [
            $this->getJson("/api/players/{$p2}")->json('data.stats.games_as_keeper'),
            $this->getJson("/api/players/{$p2}")->json('data.stats.goals_scored_as_keeper'),
        ]);
        $this->assertSame(1, $this->getJson("/api/players/{$p1}")->json('data.games.0.keeper_manches'));

        // Changer de format sur un match joué : refusé, sauf en effaçant le score.
        $this->actingAs($admin)->patchJson($url, ['best_of' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('best_of');
        $this->actingAs($admin)->patchJson($url, ['best_of' => 1, 'score_a' => null, 'score_b' => null])->assertOk()
            ->assertJsonPath('data.games.0.best_of', 1)
            ->assertJsonPath('data.games.0.sets', null)
            ->assertJsonPath('data.games.0.score_a', null);
    }

    public function test_games_are_scheduled_on_a_day_without_time(): void
    {
        $tournament = $this->makeTournament(4, 1, 2, 0);
        app(TournamentDraw::class)->run($tournament);
        $game = $tournament->games()->first();
        $admin = User::factory()->create();

        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['scheduled_at' => '2026-12-05T18:30:00Z'])
            ->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['scheduled_at' => '2026-12-05'])
            ->assertOk()
            ->assertJsonPath('data.games.0.scheduled_at', '2026-12-05');
        $this->getJson('/api/tournaments')->assertJsonPath('data.0.starts_at', '2026-12-05');
    }

    public function test_a_tournament_can_be_created_with_just_a_name(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/tournaments', ['name' => 'Noël'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.group_count', 2)
            ->assertJsonPath('data.target_score', 10);
    }

    public function test_visitors_can_read_but_not_write(): void
    {
        $tournament = $this->makeTournament(4, 1, 2, 0);

        $this->getJson('/api/tournaments')->assertOk()->assertJsonPath('data.0.name', 'Test');
        $this->getJson("/api/tournaments/{$tournament->id}")->assertOk();
        $this->postJson("/api/tournaments/{$tournament->id}/draw")->assertUnauthorized();
        $this->postJson('/api/tournaments', ['name' => 'X'])->assertUnauthorized();
    }

    public function test_bracket_result_cannot_change_once_next_game_is_played(): void
    {
        $tournament = $this->makeTournament(8, 2, 2, 2);
        app(TournamentDraw::class)->run($tournament);
        $this->playEverything($tournament, stopAtFinal: true);

        $semi = $tournament->games()->where('stage', Game::STAGE_WINNER)->where('round', 1)->first();
        $this->expectException(ValidationException::class);
        app(TournamentProgress::class)->recordScore($semi, 3, 10);
    }

    public function test_group_tie_is_broken_by_head_to_head(): void
    {
        $tournament = $this->makeTournament(3, 1, 2, 0);
        app(TournamentDraw::class)->run($tournament);
        $progress = app(TournamentProgress::class);
        $ids = $tournament->teams()->orderBy('id')->pluck('id')->all();
        [$a, $b, $c] = $ids;

        // Triangle parfait (chacun 1 V, diff 0, 10 buts marqués) sauf A-B :
        // tout le monde à égalité → confrontation directe en 3 équipes = 1 V
        // chacun, on tombe sur l'ordre alphabétique (Équipe 1, 2, 3).
        foreach ($tournament->games()->where('stage', Game::STAGE_GROUP)->get() as $game) {
            $winner = match ([$game->team_a_id, $game->team_b_id]) {
                [$a, $b], [$b, $a] => $a,
                [$b, $c], [$c, $b] => $b,
                default => $c,
            };
            $progress->recordScore($game, $winner === $game->team_a_id ? 10 : 5, $winner === $game->team_a_id ? 5 : 10);
        }

        $standings = $this->getJson("/api/tournaments/{$tournament->id}")->json('data.pools.0.standings');
        $this->assertSame([$a, $b, $c], array_column($standings, 'team_id'));
    }

    private function makeTournament(int $teams, int $groups, int $wb, int $lb): Tournament
    {
        $tournament = Tournament::create([
            'name' => 'Test', 'group_count' => $groups, 'wb_per_group' => $wb, 'lb_per_group' => $lb,
        ]);
        for ($i = 1; $i <= $teams; $i++) {
            $tournament->teams()->create([
                'name' => "Équipe {$i}",
                'player_one_id' => Player::findOrCreateByName("Joueur {$i}a")->id,
                'player_two_id' => Player::findOrCreateByName("Joueur {$i}b")->id,
            ]);
        }

        return $tournament;
    }

    private function playEverything(Tournament $tournament, bool $stopAtFinal = false): void
    {
        $progress = app(TournamentProgress::class);
        for ($guard = 0; $guard < 500; $guard++) {
            $next = $tournament->games()
                ->whereNull('score_a')
                ->whereNotNull('team_a_id')
                ->whereNotNull('team_b_id')
                ->first();
            if (! $next || ($stopAtFinal && $next->stage === Game::STAGE_FINAL)) {
                return;
            }
            $loser = random_int(0, 9);
            random_int(0, 1) ? $progress->recordScore($next, 10, $loser) : $progress->recordScore($next, $loser, 10);
        }
        $this->fail('Boucle infinie');
    }
}
