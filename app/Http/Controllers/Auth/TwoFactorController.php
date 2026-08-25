<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TotpService;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function challenge(): View|RedirectResponse
    {
        return session()->has('two_factor_user_id')
            ? view('auth.two-factor-challenge')
            : redirect()->route('login');
    }

    public function verifyChallenge(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = User::find(session('two_factor_user_id'));
        if (! $user || ! $user->two_factor_confirmed_at || ! $totp->verify((string) $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Kod za potvrdu nije ispravan ili je istekao.']);
        }

        Auth::login($user, (bool) session()->pull('two_factor_remember', false));
        $request->session()->forget('two_factor_user_id');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function setup(Request $request, TotpService $totp): View|RedirectResponse
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        if ($request->user()->two_factor_confirmed_at) {
            return redirect()->route('settings.index')->with('info', 'Dvofaktorska autentifikacija je već uključena.');
        }
        if (! $request->user()->two_factor_secret) {
            $request->user()->update([
                'two_factor_secret' => $totp->generateSecret(),
                'two_factor_confirmed_at' => null,
            ]);
        }
        $uri = $totp->uri((string) $request->user()->two_factor_secret, $request->user()->username, config('app.name'));
        $qr = new QrCode(data: $uri, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::High, size: 240, margin: 8);
        $qrDataUri = (new SvgWriter)->write($qr)->getDataUri();

        return view('auth.two-factor-setup', ['secret' => $request->user()->two_factor_secret, 'qrDataUri' => $qrDataUri]);
    }

    public function confirm(Request $request, TotpService $totp): RedirectResponse
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        if (! $request->user()->two_factor_secret || ! $totp->verify((string) $request->user()->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Kod nije ispravan. Provjeri vrijeme na telefonu i pokušaj ponovo.']);
        }
        $request->user()->update(['two_factor_confirmed_at' => now()]);

        return redirect()->route('settings.index')->with('success', 'Dvofaktorska autentifikacija je uključena.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->user()->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        return back()->with('success', 'Dvofaktorska autentifikacija je isključena.');
    }
}
