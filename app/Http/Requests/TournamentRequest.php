<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TournamentRequest extends FormRequest
{
    public function rules(): array
    {
        // Création : le nom suffit, le format prend ses valeurs par défaut
        // (Tournament::$attributes). Le format n'est modifiable qu'avant le
        // tirage (vérifié dans le contrôleur).
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:100'],
            'group_count' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'wb_per_group' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'lb_per_group' => ['sometimes', 'integer', 'min:0', 'max:8'],
            'target_score' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ];
    }
}
