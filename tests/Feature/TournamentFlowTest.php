<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
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
        $this->actingAs($admin)->patchJson("/api/games/{$game->id}", ['score_a' => 10, 'score_b' => 7])
            ->assertOk()
            ->assertJsonPath('data.games.0.score_a', 10);
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
