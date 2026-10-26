<?php

namespace App\Http\Requests;

use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PlayerRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:50']];
    }

    // Nom unique sans tenir compte de la casse ni des espaces : "romain " et
    // "Romain" désignent le même joueur.
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $existing = Player::findByName($this->input('name'));
            if ($existing && $existing->id !== $this->route('player')?->id) {
                $validator->errors()->add('name', "Le joueur {$existing->name} existe déjà.");
            }
        }];
    }
}
