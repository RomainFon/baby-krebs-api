<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tournament_id', 'number', 'stage', 'pool_id', 'round', 'position',
    'team_a_id', 'team_b_id', 'best_of', 'score_a', 'score_b', 'sets', 'keeper_a_id', 'keeper_b_id', 'scheduled_at', 'played_at',
    'seed_a', 'seed_b', 'winner_to_game_id', 'winner_to_slot', 'loser_to_game_id', 'loser_to_slot',
])]
class Game extends Model
{
    public const STAGE_GROUP = 'group';

    public const STAGE_WINNER = 'winner';

    public const STAGE_LOSER = 'loser';

    public const STAGE_FINAL = 'final';

    /** Formats possibles : BO1, BO3, BO5. */
    public const BEST_OF = [1, 3, 5];

    protected function casts(): array
    {
        return [
            'best_of' => 'integer',
            'score_a' => 'integer',
            'sets' => 'array',
            'score_b' => 'integer',
            'keeper_a_id' => 'integer',
            'keeper_b_id' => 'integer',
            'scheduled_at' => 'date:Y-m-d',
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

    /**
     * Match commencé : joué, ou BO3/BO5 dont au moins une manche est saisie
     * (les manches se saisissent au fur et à mesure, le résultat n'existe
     * qu'une fois la majorité atteinte).
     */
    public function isStarted(): bool
    {
        return $this->isPlayed() || ! empty($this->sets);
    }

    /** Requête : matchs commencés (voir isStarted). */
    public static function startedConstraint(): \Closure
    {
        return fn ($q) => $q->whereNotNull('score_a')->orWhereNotNull('sets');
    }

    public function isBracket(): bool
    {
        return $this->stage !== self::STAGE_GROUP;
    }

    /** Manches à gagner pour remporter le match (2 en BO3, 3 en BO5). */
    public function setsToWin(): int
    {
        return intdiv($this->best_of, 2) + 1;
    }

    /**
     * Points de chaque manche jouée. En BO1, une seule manche : le score.
     * En BO3/BO5, score_a/score_b sont les manches gagnées et les points
     * sont dans `sets`.
     *
     * Chaque manche a aussi son gardien de chaque côté (null si non saisi) :
     * en BO3/BO5 il peut changer d'une manche à l'autre (rangé dans `sets`),
     * en BO1 ce sont les colonnes keeper_a_id / keeper_b_id du match.
     *
     * @return list<array{a:int, b:int, keeper_a_id:?int, keeper_b_id:?int}>
     */
    public function manches(): array
    {
        // BO3/BO5 : les manches saisies, même si le match n'est pas fini.
        if ($this->best_of > 1 && $this->sets) {
            return array_map(fn ($s) => [
                'a' => (int) $s['a'],
                'b' => (int) $s['b'],
                'keeper_a_id' => isset($s['keeper_a_id']) ? (int) $s['keeper_a_id'] : null,
                'keeper_b_id' => isset($s['keeper_b_id']) ? (int) $s['keeper_b_id'] : null,
            ], $this->sets);
        }
        if (! $this->isPlayed()) {
            return [];
        }

        return [[
            'a' => $this->score_a, 'b' => $this->score_b,
            'keeper_a_id' => $this->keeper_a_id, 'keeper_b_id' => $this->keeper_b_id,
        ]];
    }

    /** Total des points marqués par un côté ("a" ou "b") sur tout le match. */
    public function points(string $slot): int
    {
        return array_sum(array_column($this->manches(), $slot));
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
