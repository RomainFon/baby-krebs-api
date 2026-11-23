<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TournamentDraw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoalsAndTeamIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    /** Tournoi à 2 équipes tiré au sort : un seul match de poule. */
    private function game(): Game
    {
        $tournament = Tournament::create(['name' => 'T', 'group_count' => 1, 'wb_per_group' => 2, 'lb_per_group' => 0]);
        foreach ([['A', 'Ana', 'Bob'], ['B', 'Cid', 'Dan']] as [$name, $one, $two]) {
            $tournament->teams()->create([
                'name' => $name,
                'player_one_id' => Player::findOrCreateByName($one)->id,
                'player_two_id' => Player::findOrCreateByName($two)->id,
            ]);
        }
        app(TournamentDraw::class)->run($tournament);

        return $tournament->games()->where('stage', Game::STAGE_GROUP)->first();
    }

    /** Gardiens par défaut : le premier joueur de chaque équipe. */
    private function keepers(Game $game): array
    {
        return [
            'keeper_a_id' => Team::find($game->team_a_id)->player_one_id,
            'keeper_b_id' => Team::find($game->team_b_id)->player_one_id,
        ];
    }

    private function id(string $name): int
    {
        return Player::findByName($name)->id;
    }

    public function test_scorers_are_saved_with_the_score(): void
    {
        $game = $this->game();
        $teamA = Team::find($game->team_a_id);
        [$a1, $a2] = $teamA->playerIds();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            ...$this->keepers($game), 'score_a' => 10, 'score_b' => 4,
            'goals' => [['player_id' => $a1, 'goals' => 7], ['player_id' => $a2, 'goals' => 3]],
        ])->assertOk()->assertJsonCount(2, 'data.games.0.goals');

        $stats = $this->getJson("/api/players/{$a1}")->json('data');
        $this->assertSame(7, $stats['stats']['goals_scored']);
        $this->assertSame(7, $stats['games'][0]['goals_scored']);
    }

    public function test_scorers_must_add_up_to_the_score(): void
    {
        $game = $this->game();
        [$a1, $a2] = Team::find($game->team_a_id)->playerIds();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            'score_a' => 10, 'score_b' => 4,
            'goals' => [['player_id' => $a1, 'goals' => 5], ['player_id' => $a2, 'goals' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors('goals');

        // Rien n'a été enregistré : la transaction a annulé le score aussi.
        $this->assertNull($game->fresh()->score_a);
    }

    public function test_scorers_must_play_in_the_game(): void
    {
        $game = $this->game();
        $outsider = Player::findOrCreateByName('Intrus')->id;

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            'score_a' => 10, 'score_b' => 0,
            'goals' => [['player_id' => $outsider, 'goals' => 10]],
        ])->assertUnprocessable();
    }

    public function test_changing_the_score_drops_stale_scorers(): void
    {
        $game = $this->game();
        [$a1] = Team::find($game->team_a_id)->playerIds();
        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            ...$this->keepers($game), 'score_a' => 10, 'score_b' => 4, 'goals' => [['player_id' => $a1, 'goals' => 10]],
        ])->assertOk();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", ['score_a' => 10, 'score_b' => 6])
            ->assertOk()->assertJsonCount(0, 'data.games.0.goals');
    }

    public function test_keepers_count_goals_conceded(): void
    {
        $game = $this->game();
        [$a1] = Team::find($game->team_a_id)->playerIds();
        [, $b2] = Team::find($game->team_b_id)->playerIds();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            'score_a' => 10, 'score_b' => 4, 'keeper_a_id' => $a1, 'keeper_b_id' => $b2,
        ])->assertOk()->assertJsonPath('data.games.0.keeper_a_id', $a1);

        $a1Stats = $this->getJson("/api/players/{$a1}")->json('data');
        $this->assertSame(1, $a1Stats['stats']['games_as_keeper']);
        $this->assertSame(4, $a1Stats['stats']['goals_conceded_as_keeper']);
        $this->assertTrue($a1Stats['games'][0]['keeper']);
        $this->assertSame(10, $this->getJson("/api/players/{$b2}")->json('data.stats.goals_conceded_as_keeper'));

        $stats = $this->getJson('/api/stats')->json('data');
        $this->assertSame($a1, $stats['best_keeper']['id']);
        $this->assertSame($b2, $stats['leakiest']['id']);

        // Un seul gardien peut être changé sans toucher à l'autre, mais pas retiré.
        [, $a2] = Team::find($game->team_a_id)->playerIds();
        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", ['keeper_a_id' => $a2])->assertOk();
        $this->assertSame($a2, $game->fresh()->keeper_a_id);
        $this->assertSame($b2, $game->fresh()->keeper_b_id);
        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", ['keeper_a_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('keeper_a_id');
        $this->assertSame($a2, $game->fresh()->keeper_a_id);

        // Effacer le score efface les gardiens.
        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", ['score_a' => null, 'score_b' => null])->assertOk();
        $this->assertNull($game->fresh()->keeper_b_id);
    }

    public function test_attacker_and_defender_stats_follow_the_keeper(): void
    {
        $game = $this->game();
        [$a1, $a2] = Team::find($game->team_a_id)->playerIds();
        [$b1, $b2] = Team::find($game->team_b_id)->playerIds();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            'score_a' => 10, 'score_b' => 4, 'keeper_a_id' => $a1, 'keeper_b_id' => $b1,
            'goals' => [['player_id' => $a1, 'goals' => 2], ['player_id' => $a2, 'goals' => 8]],
        ])->assertOk();

        $a1Stats = $this->getJson("/api/players/{$a1}")->json('data.stats');
        $this->assertEquals([1, 1, 1, 0], [$a1Stats['games_as_keeper'], $a1Stats['wins_as_keeper'], $a1Stats['keeper_win_rate'], $a1Stats['games_as_attacker']]);
        $this->assertSame(0, $a1Stats['goals_scored_as_attacker']);
        $this->assertSame([2, 1], [$a1Stats['goals_scored_as_keeper'], $a1Stats['games_with_goals_as_keeper']]);

        $a2Stats = $this->getJson("/api/players/{$a2}")->json('data.stats');
        $this->assertEquals([1, 1, 1, 8], [$a2Stats['games_as_attacker'], $a2Stats['wins_as_attacker'], $a2Stats['attacker_win_rate'], $a2Stats['goals_scored_as_attacker']]);

        $b2Stats = $this->getJson("/api/players/{$b2}")->json('data.stats');
        $this->assertSame([1, 0, 0], [$b2Stats['games_as_attacker'], $b2Stats['wins_as_attacker'], $b2Stats['games_as_keeper']]);
    }

    public function test_keeper_must_belong_to_the_team(): void
    {
        $game = $this->game();
        [$b1] = Team::find($game->team_b_id)->playerIds();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", [
            'score_a' => 10, 'score_b' => 4, 'keeper_a_id' => $b1,
        ])->assertUnprocessable()->assertJsonValidationErrors('keeper_a_id');
        $this->assertNull($game->fresh()->score_a);

        // Pas de gardien sur un match sans score.
        [$a1] = Team::find($game->team_a_id)->playerIds();
        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", ['keeper_a_id' => $a1])
            ->assertUnprocessable();
    }

    public function test_teams_get_distinct_default_colors(): void
    {
        foreach (['Ana', 'Bob', 'Cid', 'Dan'] as $name) {
            Player::findOrCreateByName($name);
        }
        $tournament = Tournament::create(['name' => 'U']);
        foreach ([['X', 'Ana', 'Bob'], ['Y', 'Cid', 'Dan']] as [$name, $one, $two]) {
            $this->actingAs($this->admin)->postJson("/api/tournaments/{$tournament->id}/teams", [
                'name' => $name, 'players' => [$this->id($one), $this->id($two)],
            ])->assertOk();
        }
        $assigned = $tournament->teams()->pluck('color');
        $this->assertCount(2, $assigned->unique());
        $this->assertEmpty(array_diff($assigned->all(), Team::COLORS));
    }

    public function test_team_color_can_be_picked(): void
    {
        $game = $this->game();
        $team = Team::find($game->team_a_id);
        $payload = ['name' => $team->name, 'players' => $team->playerIds()];

        $this->actingAs($this->admin)->patchJson("/api/teams/{$team->id}", [...$payload, 'color' => '#12AB9F'])
            ->assertOk();
        $this->assertSame('#12ab9f', $team->fresh()->color);

        // Sans couleur envoyée, celle en place est conservée.
        $this->actingAs($this->admin)->patchJson("/api/teams/{$team->id}", $payload)->assertOk();
        $this->assertSame('#12ab9f', $team->fresh()->color);

        $this->actingAs($this->admin)->patchJson("/api/teams/{$team->id}", [...$payload, 'color' => 'red'])
            ->assertJsonValidationErrors('color');
    }

    public function test_team_short_name_defaults_to_initials_and_can_be_set(): void
    {
        $this->assertSame('CQP', (new Team(['name' => 'Chauve qui peut']))->shortName());
        $this->assertSame('SUN', (new Team(['name' => 'Sunny']))->shortName());
        $this->assertSame('MDV', (new Team(['name' => "Mains d'Velours"]))->shortName());

        $game = $this->game();
        $team = Team::find($game->team_a_id);
        $payload = ['name' => $team->name, 'players' => $team->playerIds()];

        $this->actingAs($this->admin)->patchJson("/api/teams/{$team->id}", [...$payload, 'short_name' => 'abc'])
            ->assertOk();
        $this->assertSame('ABC', $team->fresh()->short_name);

        // Vidé : retour aux initiales calculées.
        $this->actingAs($this->admin)->patchJson("/api/teams/{$team->id}", [...$payload, 'short_name' => ''])
            ->assertOk();
        $this->assertNull($team->fresh()->short_name);

        $this->actingAs($this->admin)->patchJson("/api/teams/{$team->id}", [...$payload, 'short_name' => 'A B'])
            ->assertJsonValidationErrors('short_name');
    }

    public function test_tournament_rules_are_editable_by_admin_only(): void
    {
        $tournament = $this->game()->tournament;

        $this->patchJson("/api/tournaments/{$tournament->id}", ['rules' => 'Pas de roulette.'])
            ->assertUnauthorized();

        $this->actingAs($this->admin)->patchJson("/api/tournaments/{$tournament->id}", ['rules' => 'Pas de roulette.'])
            ->assertOk()->assertJsonPath('data.rules', 'Pas de roulette.');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/tournaments/{$tournament->id}")->assertJsonPath('data.rules', 'Pas de roulette.');
    }

    public function test_logo_upload_and_serving(): void
    {
        Storage::fake('local');
        $game = $this->game();
        $team = Team::find($game->team_a_id);

        $this->actingAs($this->admin)
            ->post("/api/teams/{$team->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk();

        $url = $team->fresh()->logoUrl();
        $this->assertNotNull($url);
        $this->get($url)->assertOk();

        $this->actingAs($this->admin)->post("/api/teams/{$team->id}/logo", ['logo' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->actingAs($this->admin)->deleteJson("/api/teams/{$team->id}/logo")->assertOk();
        $this->assertNull($team->fresh()->logo_path);
    }
}
