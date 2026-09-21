<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        User::create($data);

        return back()->with('success', 'Korisnik je kreiran.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:80', Rule::unique('users', 'username')->ignore($user)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $deactivatingSelf = $request->user()->getKey() === $user->getKey() && ! $request->boolean('is_active');
        if ($deactivatingSelf) {
            return back()->withErrors(['user' => 'Ne možeš deaktivirati vlastiti račun.']);
        }

        $removingLastAdmin = $user->isAdministrator()
            && ($data['role'] !== User::ROLE_ADMINISTRATOR || ! $request->boolean('is_active'))
            && User::query()
                ->where('id', '<>', $user->id)
                ->where('role', User::ROLE_ADMINISTRATOR)
                ->where('is_active', true)
                ->doesntExist();
        if ($removingLastAdmin) {
            return back()->withErrors(['user' => 'Posljednji aktivni administrator mora ostati administrator.']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $user->update($data);

        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return back()->with('success', 'Korisnički račun je ažuriran.');
    }
}
