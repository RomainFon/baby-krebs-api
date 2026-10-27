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
            'score_a' => 10, 'score_b' => 4,
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
            'score_a' => 10, 'score_b' => 4, 'goals' => [['player_id' => $a1, 'goals' => 10]],
        ])->assertOk();

        $this->actingAs($this->admin)->patchJson("/api/games/{$game->id}", ['score_a' => 10, 'score_b' => 6])
            ->assertOk()->assertJsonCount(0, 'data.games.0.goals');
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
