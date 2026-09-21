<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (User::query()->where('email', $data['email'])->where('is_active', true)->exists()) {
            Password::sendResetLink($data);
        }
        ActivityLog::create(['user_id' => null, 'method' => 'AUTH', 'route_name' => 'password.requested', 'path' => '/zaboravljena-lozinka', 'status_code' => 200, 'metadata' => ['event' => 'password_reset_requested'], 'ip_address' => $request->ip()]);

        // Isti odgovor sprječava otkrivanje postoji li račun sa unesenim emailom.
        return back()->with('status', 'Ako aktivan račun s tim emailom postoji, poslali smo link za promjenu lozinke.');
    }
}
