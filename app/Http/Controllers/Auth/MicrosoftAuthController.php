<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

// Déclaré dans routes/web.php (pas api.php) : voir le commentaire sur ces
// routes.
//
// Accès fermé : aucune création de compte à la volée. La connexion Microsoft
// ne fonctionne que si l'email du compte Microsoft correspond à un admin déjà
// existant (créé avec `php artisan baby:create-admin`) : la table users fait
// office de liste blanche.
class MicrosoftAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('microsoft')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $login = config('app.frontend_url').'/login';

        try {
            $microsoftUser = Socialite::driver('microsoft')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect($login.'?error=microsoft');
        }

        // Microsoft expose l'adresse dans `mail` (comptes pro, souvent) et/ou
        // `userPrincipalName` (identifiant de connexion, = email pour les
        // comptes personnels) : on accepte l'un ou l'autre.
        $emails = collect([$microsoftUser->getEmail(), $microsoftUser->getRaw()['mail'] ?? null])
            ->filter()
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        $user = User::query()->where('microsoft_id', $microsoftUser->getId())->first()
            ?? User::query()->whereIn(DB::raw('lower(email)'), $emails)->first();

        if (! $user) {
            return redirect($login.'?error=unauthorized');
        }

        if ($user->microsoft_id !== $microsoftUser->getId()) {
            $user->update(['microsoft_id' => $microsoftUser->getId()]);
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect(config('app.frontend_url').'/admin');
    }
}
