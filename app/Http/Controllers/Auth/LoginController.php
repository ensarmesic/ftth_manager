<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $throttleKey = Str::lower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => "Previše pokušaja prijave. Pokušaj ponovo za {$seconds} sekundi.",
            ]);
        }

        $activeAccount = User::query()->where('username', $credentials['username'])->where('is_active', true)->exists();
        if (! $activeAccount || ! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);
            ActivityLog::create(['user_id' => null, 'method' => 'AUTH', 'route_name' => 'login.failed', 'path' => '/prijava', 'status_code' => 401, 'metadata' => ['event' => 'login_failed', 'username' => $credentials['username']], 'ip_address' => $request->ip()]);

            throw ValidationException::withMessages([
                'username' => 'Uneseni podaci za prijavu nisu ispravni.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();
        $user?->forceFill(['last_login_at' => now()])->save();
        ActivityLog::create(['user_id' => $user?->id, 'method' => 'AUTH', 'route_name' => 'login.success', 'path' => '/prijava', 'status_code' => 200, 'metadata' => ['event' => 'login_success'], 'ip_address' => $request->ip()]);
        if ($user?->two_factor_confirmed_at && $user->two_factor_secret) {
            $request->session()->put([
                'two_factor_user_id' => $user->id,
                'two_factor_remember' => $request->boolean('remember'),
            ]);
            Auth::logout();

            return redirect()->route('two-factor.challenge');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        ActivityLog::create(['user_id' => $request->user()?->id, 'method' => 'AUTH', 'route_name' => 'logout', 'path' => '/odjava', 'status_code' => 200, 'metadata' => ['event' => 'logout'], 'ip_address' => $request->ip()]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
