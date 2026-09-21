<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_a_user(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin)->post(route('settings.users.store'), [
            'name' => 'Terenski Radnik',
            'username' => 'teren1',
            'email' => 'teren1@example.test',
            'role' => User::ROLE_FIELD,
            'password' => 'SigurnaLozinka123',
            'password_confirmation' => 'SigurnaLozinka123',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'username' => 'teren1',
            'role' => User::ROLE_FIELD,
            'is_active' => true,
        ]);
    }

    public function test_non_administrator_cannot_manage_users(): void
    {
        $designer = User::factory()->designer()->create();

        $this->actingAs($designer)->post(route('settings.users.store'), [])->assertForbidden();
    }

    public function test_administrator_can_reset_password_and_deactivate_another_user(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->designer()->create();

        $this->actingAs($admin)->put(route('settings.users.update', $user), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => User::ROLE_VIEWER,
            'is_active' => '0',
            'password' => 'NovaSigurnaLozinka456',
            'password_confirmation' => 'NovaSigurnaLozinka456',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertFalse($user->is_active);
        $this->assertSame(User::ROLE_VIEWER, $user->role);
        $this->assertTrue(Hash::check('NovaSigurnaLozinka456', $user->password));
    }

    public function test_administrator_cannot_deactivate_self(): void
    {
        $admin = User::factory()->administrator()->create();
        $payload = [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => User::ROLE_ADMINISTRATOR,
            'is_active' => '0',
        ];

        $this->actingAs($admin)->put(route('settings.users.update', $admin), $payload)
            ->assertSessionHasErrors('user');

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->isAdministrator());
    }

    public function test_last_active_administrator_cannot_be_demoted(): void
    {
        User::query()->delete();
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin)->put(route('settings.users.update', $admin), [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'role' => User::ROLE_VIEWER,
            'is_active' => '1',
        ])->assertSessionHasErrors('user');

        $this->assertTrue($admin->fresh()->isAdministrator());
    }

    public function test_inactive_user_cannot_log_in_and_successful_login_is_recorded(): void
    {
        auth()->logout();
        $inactive = User::factory()->create([
            'username' => 'inactive',
            'password' => 'SigurnaLozinka123',
            'is_active' => false,
        ]);

        $this->post(route('login.store'), [
            'username' => $inactive->username,
            'password' => 'SigurnaLozinka123',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        $active = User::factory()->create(['username' => 'active', 'password' => 'SigurnaLozinka123']);
        $this->post(route('login.store'), [
            'username' => $active->username,
            'password' => 'SigurnaLozinka123',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotNull($active->fresh()->last_login_at);
    }
}
