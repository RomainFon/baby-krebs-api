<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class Player extends Model
{
    public function isInATeam(): bool
    {
        return Team::query()
            ->where('player_one_id', $this->id)
            ->orWhere('player_two_id', $this->id)
            ->exists();
    }

    // Insensible à la casse et aux espaces : "romain " et "Romain" désignent
    // le même joueur, pour ne pas éclater ses futures stats en doublons.
    public static function findByName(string $name): ?self
    {
        return self::query()->whereRaw('lower(name) = ?', [mb_strtolower(self::normalize($name))])->first();
    }

    public static function findOrCreateByName(string $name): self
    {
        return self::findByName($name) ?? self::create(['name' => self::normalize($name)]);
    }

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }
}
