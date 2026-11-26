<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GameRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Jour du match (AAAA-MM-JJ), sans heure.
            'scheduled_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Format du match : BO1, BO3 ou BO5 (modifiable tant qu'il n'est pas joué).
            'best_of' => ['sometimes', 'integer', 'in:1,3,5'],
            'score_a' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'score_b' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            // BO3/BO5 : points de chaque manche, le résultat en découle.
            'sets' => ['sometimes', 'nullable', 'array', 'max:5'],
            'sets.*.a' => ['required', 'integer', 'min:0', 'max:99'],
            'sets.*.b' => ['required', 'integer', 'min:0', 'max:99'],
            // Gardien de chaque équipe dans la manche (obligatoire, peut
            // changer d'une manche à l'autre).
            'sets.*.keeper_a_id' => ['sometimes', 'nullable', 'integer'],
            'sets.*.keeper_b_id' => ['sometimes', 'nullable', 'integer'],
            // Buteurs (facultatif) : liste complète, remplace l'existant. Une
            // entrée par joueur et par manche (manche 1 en BO1).
            'goals' => ['sometimes', 'array', 'max:20'],
            'goals.*.player_id' => ['required', 'integer'],
            'goals.*.manche' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'goals.*.goals' => ['required', 'integer', 'min:0', 'max:99'],
            // Gardiens (BO1) : un joueur de chaque équipe, obligatoires sur un
            // match joué. En BO3/BO5, ils sont dans chaque manche (`sets`).
            'keeper_a_id' => ['sometimes', 'nullable', 'integer'],
            'keeper_b_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
