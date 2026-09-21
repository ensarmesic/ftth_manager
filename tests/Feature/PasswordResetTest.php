<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_request_a_password_reset_link(): void
    {
        auth()->logout();
        Notification::fake();
        $user = User::factory()->create(['is_active' => true]);

        $this->get(route('password.request'))->assertOk()->assertSee('Oporavak pristupa');
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_request_does_not_reveal_unknown_or_inactive_accounts(): void
    {
        auth()->logout();
        Notification::fake();
        $inactive = User::factory()->create(['is_active' => false]);

        $unknown = $this->post(route('password.email'), ['email' => 'unknown@example.test']);
        $inactiveResponse = $this->post(route('password.email'), ['email' => $inactive->email]);

        $unknown->assertSessionHas('status');
        $inactiveResponse->assertSessionHas('status');
        $this->assertSame($unknown->getSession()->get('status'), $inactiveResponse->getSession()->get('status'));
        Notification::assertNothingSent();
    }

    public function test_active_user_can_set_a_new_password_with_a_valid_token(): void
    {
        auth()->logout();
        $user = User::factory()->create(['password' => 'StaraSigurnaLozinka123', 'is_active' => true]);
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Postavi novu lozinku');

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NovaSigurnaLozinka456',
            'password_confirmation' => 'NovaSigurnaLozinka456',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('NovaSigurnaLozinka456', $user->fresh()->password));
    }

    public function test_invalid_token_cannot_change_password(): void
    {
        auth()->logout();
        $user = User::factory()->create(['password' => 'StaraSigurnaLozinka123', 'is_active' => true]);

        $this->post(route('password.store'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'NovaSigurnaLozinka456',
            'password_confirmation' => 'NovaSigurnaLozinka456',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('StaraSigurnaLozinka123', $user->fresh()->password));
    }
}
