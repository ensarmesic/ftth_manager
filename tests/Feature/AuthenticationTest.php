<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/mapa')->assertRedirect(route('login'));
        $this->get('/postavke/backup')->assertRedirect(route('login'));
    }

    public function test_user_can_log_in_and_log_out(): void
    {
        auth()->logout();
        $user = User::factory()->create(['username' => 'admin', 'password' => 'sigurna-lozinka']);

        $this->post(route('login.store'), [
            'username' => 'admin',
            'password' => 'sigurna-lozinka',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'StaraLozinka123']);
        $this->actingAs($user);

        $this->put(route('password.update'), [
            'current_password' => 'StaraLozinka123',
            'password' => 'NovaSigurnaLozinka456',
            'password_confirmation' => 'NovaSigurnaLozinka456',
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'NovaSigurnaLozinka456',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        auth()->logout();
        User::factory()->create(['username' => 'limit-admin', 'password' => 'IspravnaLozinka123']);

        foreach (range(1, 5) as $_attempt) {
            $this->post(route('login.store'), [
                'username' => 'limit-admin',
                'password' => 'PogresnaLozinka',
            ])->assertSessionHasErrors('username');
        }

        $response = $this->post(route('login.store'), [
            'username' => 'limit-admin',
            'password' => 'IspravnaLozinka123',
        ])->assertSessionHasErrors('username');

        $this->assertStringContainsString('Previše pokušaja prijave', $response->getSession()->get('errors')->first('username'));
        $this->assertGuest();
    }

    public function test_successful_login_clears_previous_failed_attempts(): void
    {
        auth()->logout();
        User::factory()->create(['username' => 'reset-admin', 'password' => 'IspravnaLozinka123']);

        $this->post(route('login.store'), [
            'username' => 'reset-admin',
            'password' => 'PogresnaLozinka',
        ])->assertSessionHasErrors('username');

        $this->post(route('login.store'), [
            'username' => 'reset-admin',
            'password' => 'IspravnaLozinka123',
        ])->assertRedirect(route('dashboard'));

        $key = 'reset-admin|127.0.0.1';
        $this->assertSame(0, RateLimiter::attempts($key));
    }
}
