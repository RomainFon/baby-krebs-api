<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GameRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'score_a' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'score_b' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            // Buteurs (facultatif) : liste complète, remplace l'existant.
            'goals' => ['sometimes', 'array', 'max:4'],
            'goals.*.player_id' => ['required', 'integer', 'distinct'],
            'goals.*.goals' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }
}
