<?php

namespace App\Http\Requests;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TeamRequest extends FormRequest
{
    public function rules(): array
    {
        $tournamentId = $this->route('tournament')?->id ?? $this->route('team')?->tournament_id;

        return [
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('teams', 'name')
                    ->where('tournament_id', $tournamentId)
                    ->ignore($this->route('team')?->id),
            ],
            // Uniquement des joueurs existants (créés au préalable par l'admin).
            'players' => ['required', 'array', 'size:2'],
            'players.*' => ['required', 'integer', 'distinct', Rule::exists('players', 'id')],
            // Couleur de l'écusson ; attribuée automatiquement si absente.
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    // Un joueur ne peut faire partie que d'une seule équipe par tournoi.
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $currentTeam = $this->route('team');
            $tournamentId = $this->route('tournament')?->id ?? $currentTeam?->tournament_id;

            foreach ($this->input('players') as $index => $playerId) {
                $team = Team::query()
                    ->where('tournament_id', $tournamentId)
                    ->when($currentTeam, fn ($q) => $q->whereKeyNot($currentTeam->id))
                    ->where(fn ($q) => $q->where('player_one_id', $playerId)->orWhere('player_two_id', $playerId))
                    ->first();
                if ($team) {
                    $name = Player::find($playerId)->name;
                    $validator->errors()->add("players.{$index}", "{$name} joue déjà dans l'équipe {$team->name}.");
                }
            }
        }];
    }

    public function attributes(): array
    {
        return ['players.*' => 'joueur'];
    }

    public function messages(): array
    {
        return [
            'players.*.distinct' => 'Les deux joueurs doivent être différents.',
            'color.regex' => 'La couleur doit être au format #rrggbb.',
        ];
    }
}
