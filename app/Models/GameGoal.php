<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_id', 'player_id', 'goals'])]
class GameGoal extends Model
{
    protected function casts(): array
    {
        return ['goals' => 'integer'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
