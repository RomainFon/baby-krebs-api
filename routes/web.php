<?php

use App\Http\Controllers\Auth\MicrosoftAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// SSO Microsoft. Volontairement dans web.php et pas api.php : le middleware
// Sanctum EnsureFrontendRequestsAreStateful (appliqué à api.php) n'active la
// session que pour les requêtes venant du SPA (Referer/Origin connu). Le
// callback est une navigation depuis login.microsoftonline.com : il ne
// passerait jamais par la session. Le groupe "web" démarre toujours une
// session (pattern standard Socialite). Le préfixe /api/... est gardé pour
// que ces routes passent par le proxy Vite en dev et le routage Traefik en
// prod : le cookie de session est ainsi posé sur le domaine du SPA.
Route::get('/api/auth/microsoft/redirect', [MicrosoftAuthController::class, 'redirect']);
Route::get('/api/auth/microsoft/callback', [MicrosoftAuthController::class, 'callback']);
