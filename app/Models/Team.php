<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tournament_id', 'pool_id', 'name', 'short_name', 'color', 'logo_path', 'player_one_id', 'player_two_id'])]
class Team extends Model
{
    // Palette des écussons par défaut : couleurs franches et distinctes entre
    // elles, en évitant le lime de l'interface.
    public const COLORS = [
        '#e63946', '#3a86ff', '#ff9f1c', '#8338ec', '#ff4d9d', '#06d6a0',
        '#ffd60a', '#4cc9f0', '#f77f00', '#9d4edd', '#2ec4b6', '#ef476f',
    ];

    /**
     * Couleur tirée au hasard parmi celles pas encore prises dans le tournoi
     * (n'importe laquelle si la palette est épuisée).
     */
    public static function randomColor(Tournament $tournament): string
    {
        $used = $tournament->teams()->pluck('color')->all();
        $free = array_values(array_diff(self::COLORS, $used));

        return collect($free ?: self::COLORS)->random();
    }

    /**
     * Diminutif affiché : celui saisi par l'admin, sinon les initiales du nom
     * ("Chauve qui peut" → "CQP"), ou les 3 premières lettres d'un nom en un
     * seul mot.
     */
    public function shortName(): string
    {
        if ($this->short_name) {
            return $this->short_name;
        }
        $words = preg_split("/[\\s\\-'’]+/u", trim($this->name), -1, PREG_SPLIT_NO_EMPTY);
        $short = count($words) > 1
            ? implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 5)))
            : mb_substr($words[0] ?? '?', 0, 3);

        return mb_strtoupper($short);
    }

    /** URL relative (même domaine que le SPA), versionnée pour le cache. */
    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? "/api/teams/{$this->id}/logo?v=".substr(md5($this->logo_path), 0, 8)
            : null;
    }

    /** Joueurs de l'équipe, dans l'ordre. */
    public function playerIds(): array
    {
        return [$this->player_one_id, $this->player_two_id];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function playerOne(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_one_id');
    }

    public function playerTwo(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_two_id');
    }
}
