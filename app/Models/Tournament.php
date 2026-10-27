<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'status', 'group_count', 'wb_per_group', 'lb_per_group', 'target_score'])]
class Tournament extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_GROUPS = 'groups';

    public const STATUS_BRACKET = 'bracket';

    public const STATUS_FINISHED = 'finished';

    // Format par défaut à la création (seul le nom est demandé), à ajuster
    // ensuite dans l'admin avant le tirage. Calqué sur l'exemple : 2 poules,
    // 2 premiers en winner bracket, 2 suivants en loser bracket.
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'group_count' => 2,
        'wb_per_group' => 2,
        'lb_per_group' => 2,
        'target_score' => 10,
    ];

    protected function casts(): array
    {
        return [
            'group_count' => 'integer',
            'wb_per_group' => 'integer',
            'lb_per_group' => 'integer',
            'target_score' => 'integer',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class)->orderBy('position');
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class)->orderBy('number');
    }

    /** Rechargé avec tout ce qu'affiche TournamentDetailResource. */
    public function withDetail(): self
    {
        return $this->fresh()->load(['teams.playerOne', 'teams.playerTwo', 'pools', 'games.goals']);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
