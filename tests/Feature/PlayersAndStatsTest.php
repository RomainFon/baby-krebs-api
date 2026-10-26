<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TournamentDraw;
use App\Services\TournamentProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayersAndStatsTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(): Tournament
    {
        return Tournament::create(['name' => 'T', 'group_count' => 1, 'wb_per_group' => 2, 'lb_per_group' => 0]);
    }

    /** @return list<int> */
    private function players(string ...$names): array
    {
        return array_map(fn ($n) => Player::findOrCreateByName($n)->id, $names);
    }

    public function test_a_player_cannot_join_two_teams_of_the_same_tournament(): void
    {
        $tournament = $this->tournament();
        $admin = User::factory()->create();
        [$romain, $lea, $hugo] = $this->players('Romain', 'Léa', 'Hugo');

        $this->actingAs($admin)->postJson("/api/tournaments/{$tournament->id}/teams", ['name' => 'A', 'players' => [$romain, $lea]])
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/tournaments/{$tournament->id}/teams", ['name' => 'B', 'players' => [$romain, $hugo]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['players.0']);

        // Mais rien n'empêche de rejouer dans un autre tournoi.
        $other = $this->tournament();
        $this->actingAs($admin)->postJson("/api/tournaments/{$other->id}/teams", ['name' => 'B', 'players' => [$romain, $hugo]])
            ->assertOk();
    }

    public function test_teams_only_accept_existing_players(): void
    {
        $tournament = $this->tournament();
        [$romain] = $this->players('Romain');

        $this->actingAs(User::factory()->create())
            ->postJson("/api/tournaments/{$tournament->id}/teams", ['name' => 'A', 'players' => [$romain, 9999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['players.1']);
    }

    public function test_editing_a_team_keeps_its_own_players(): void
    {
        $tournament = $this->tournament();
        $admin = User::factory()->create();
        $players = $this->players('Romain', 'Léa');
        $this->actingAs($admin)->postJson("/api/tournaments/{$tournament->id}/teams", ['name' => 'A', 'players' => $players]);
        $team = $tournament->teams()->first();

        $this->actingAs($admin)->patchJson("/api/teams/{$team->id}", ['name' => 'A bis', 'players' => $players])
            ->assertOk();
    }

    public function test_admin_manages_players(): void
    {
        $admin = User::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/players', ['name' => '  Romain  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Romain')
            ->json('data.id');
        $this->actingAs($admin)->postJson('/api/players', ['name' => 'romain'])
            ->assertUnprocessable();
        $this->actingAs($admin)->patchJson("/api/players/{$id}", ['name' => 'Romain F.'])
            ->assertOk();

        // Engagé dans une équipe : plus supprimable.
        [$lea] = $this->players('Léa');
        $this->tournament()->teams()->create(['name' => 'A', 'player_one_id' => $id, 'player_two_id' => $lea]);
        $this->actingAs($admin)->deleteJson("/api/players/{$id}")->assertUnprocessable();

        [$solo] = $this->players('Solo');
        $this->actingAs($admin)->deleteJson("/api/players/{$solo}")->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/players', ['name' => 'Intrus'])->assertUnauthorized();
    }

    public function test_player_and_global_stats(): void
    {
        $tournament = $this->tournament();
        foreach ([['A', 'Ana', 'Bob'], ['B', 'Cid', 'Dan']] as [$name, $one, $two]) {
            $tournament->teams()->create([
                'name' => $name,
                'player_one_id' => Player::findOrCreateByName($one)->id,
                'player_two_id' => Player::findOrCreateByName($two)->id,
            ]);
        }
        app(TournamentDraw::class)->run($tournament);
        $progress = app(TournamentProgress::class);
        $group = $tournament->games()->where('stage', Game::STAGE_GROUP)->first();
        $progress->recordScore($group, 10, 0);
        // 2 équipes en WB : finale WB, puis grande finale contre le perdant.
        while ($next = $tournament->games()->whereNull('score_a')->whereNotNull('team_a_id')->whereNotNull('team_b_id')->first()) {
            $progress->recordScore($next, 10, 9);
        }

        $ana = Player::findByName('Ana');
        $stats = $this->getJson("/api/players/{$ana->id}")->assertOk()->json('data');
        $this->assertSame('Bob', $stats['partners'][0]['name']);
        $this->assertSame(count($stats['games']), $stats['stats']['played']);

        $global = $this->getJson('/api/stats')->assertOk()->json('data');
        $this->assertSame(1, $global['fanny_victim']['stats']['fannys_taken']);
        $this->assertNotNull($global['most_titles']);

        $list = $this->getJson('/api/tournaments')->json('data.0');
        $this->assertNotNull($list['champion']);
        $this->assertSame($list['games_total'], $list['games_played']);
    }
}
