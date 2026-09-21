<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class ResetPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->string('email')->toString()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', Rule::exists('users', 'email')->where('is_active', true)],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            ActivityLog::create(['user_id' => null, 'method' => 'AUTH', 'route_name' => 'password.reset.failed', 'path' => '/nova-lozinka', 'status_code' => 422, 'metadata' => ['event' => 'password_reset_failed'], 'ip_address' => $request->ip()]);

            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }

        $userId = User::query()->where('email', $data['email'])->value('id');
        ActivityLog::create(['user_id' => $userId, 'method' => 'AUTH', 'route_name' => 'password.reset.success', 'path' => '/nova-lozinka', 'status_code' => 200, 'metadata' => ['event' => 'password_reset_success'], 'ip_address' => $request->ip()]);

        return redirect()->route('login')->with('status', 'Lozinka je promijenjena. Možeš se prijaviti novom lozinkom.');
    }
}
