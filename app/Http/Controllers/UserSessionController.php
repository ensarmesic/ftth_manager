<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserSessionController extends Controller
{
    public function destroy(Request $request, string $session): RedirectResponse
    {
        abort_if($session === $request->session()->getId(), 422, 'Trenutnu sesiju prekini odjavom.');
        DB::table(config('session.table', 'sessions'))->where('id', $session)->delete();

        return back()->with('success', 'Sesija je prekinuta.');
    }

    public function destroyOthers(Request $request, User $user): RedirectResponse
    {
        $query = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id);
        if ($request->user()->is($user)) {
            $query->where('id', '<>', $request->session()->getId());
        }
        $count = $query->delete();

        return back()->with('success', "Prekinuto sesija: {$count}.");
    }
}
