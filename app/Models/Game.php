<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tournament_id', 'number', 'stage', 'pool_id', 'round', 'position',
    'team_a_id', 'team_b_id', 'score_a', 'score_b', 'scheduled_at', 'played_at',
    'seed_a', 'seed_b', 'winner_to_game_id', 'winner_to_slot', 'loser_to_game_id', 'loser_to_slot',
])]
class Game extends Model
{
    public const STAGE_GROUP = 'group';

    public const STAGE_WINNER = 'winner';

    public const STAGE_LOSER = 'loser';

    public const STAGE_FINAL = 'final';

    protected function casts(): array
    {
        return [
            'score_a' => 'integer',
            'score_b' => 'integer',
            'scheduled_at' => 'datetime',
            'played_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(GameGoal::class);
    }

    public function isPlayed(): bool
    {
        return $this->score_a !== null && $this->score_b !== null;
    }

    public function isBracket(): bool
    {
        return $this->stage !== self::STAGE_GROUP;
    }

    public function winnerId(): ?int
    {
        if (! $this->isPlayed()) {
            return null;
        }

        return $this->score_a > $this->score_b ? $this->team_a_id : $this->team_b_id;
    }

    public function loserId(): ?int
    {
        if (! $this->isPlayed()) {
            return null;
        }

        return $this->score_a > $this->score_b ? $this->team_b_id : $this->team_a_id;
    }
}
