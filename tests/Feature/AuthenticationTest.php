<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $project = Project::create([
            'name' => 'Zaštićeni projekat', 'code' => 'AUTH-BACKUP',
            'location' => 'Test', 'status' => 'planning',
        ]);
        auth()->logout();

        $this->get('/mapa')->assertRedirect(route('login'));
        $this->get('/postavke/backup')->assertRedirect(route('login'));
        $this->get(route('projects.backup', $project))->assertRedirect(route('login'));
        $this->post(route('projects.restore'))->assertRedirect(route('login'));
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

    public function test_enabled_two_factor_authentication_is_required_after_password_login(): void
    {
        auth()->logout();
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'username' => '2fa-admin',
            'password' => 'SigurnaLozinka123',
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'SigurnaLozinka123',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertSame($user->id, session('two_factor_user_id'));

        $this->post(route('two-factor.verify'), [
            'code' => $totp->currentCode($secret),
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('two_factor_user_id'));
    }

    public function test_active_two_factor_setup_cannot_be_replaced_by_visiting_setup_page(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMINISTRATOR,
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('two-factor.setup'))
            ->assertRedirect(route('settings.index'));

        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $user->fresh()->two_factor_secret);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_administrator_can_open_two_factor_setup_and_receive_a_secret(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMINISTRATOR,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertSee('Uključi dvofaktorsku autentifikaciju');

        $this->assertNotEmpty($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }
}
