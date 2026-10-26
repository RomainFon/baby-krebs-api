<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class MicrosoftAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMicrosoftUser(string $id, string $upn, ?string $mail = null): void
    {
        $user = (new SocialiteUser)->setRaw(['mail' => $mail, 'userPrincipalName' => $upn])
            ->map(['id' => $id, 'email' => $upn, 'name' => 'Test']);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    public function test_redirects_to_microsoft(): void
    {
        $this->get('/api/auth/microsoft/redirect')
            ->assertRedirectContains('login.microsoftonline.com');
    }

    public function test_unknown_account_is_refused(): void
    {
        $this->fakeMicrosoftUser('ms-1', 'inconnu@outlook.fr');

        $this->get('/api/auth/microsoft/callback')
            ->assertRedirect(config('app.frontend_url').'/login?error=unauthorized');
        $this->assertGuest();
    }

    public function test_existing_admin_is_logged_in_and_linked(): void
    {
        $admin = User::factory()->create(['email' => 'romain@exemple.fr']);
        // Email pro dans `mail`, identifiant différent dans `userPrincipalName`.
        $this->fakeMicrosoftUser('ms-42', 'rfontaine@tenant.onmicrosoft.com', 'Romain@Exemple.fr');

        $this->get('/api/auth/microsoft/callback')
            ->assertRedirect(config('app.frontend_url').'/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('ms-42', $admin->fresh()->microsoft_id);
    }

    public function test_microsoft_error_redirects_with_message(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('invalid state'));
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->get('/api/auth/microsoft/callback')
            ->assertRedirect(config('app.frontend_url').'/login?error=microsoft');
    }
}
