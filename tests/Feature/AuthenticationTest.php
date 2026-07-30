<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
