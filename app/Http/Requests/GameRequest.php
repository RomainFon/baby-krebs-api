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
        ];
    }
}
