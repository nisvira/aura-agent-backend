<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Session login for the test console: YRU Passport (button on the page) or a
 * local admin account created with `php artisan app:create-admin`.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = Str::lower($credentials['email']);

        // Passport-only users have no password, so they can never match here.
        if (! Auth::attempt($credentials)) {
            AuditLog::record('auth.login.failed', metadata: ['method' => 'password', 'email' => $credentials['email']]);

            return back()->withErrors(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'])->onlyInput('email');
        }

        $request->session()->regenerate();
        AuditLog::record('auth.login.success', Auth::user(), metadata: ['method' => 'password', 'to' => 'console']);

        return redirect()->intended(route('console'));
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLog::record('auth.logout', $request->user(), metadata: ['method' => 'session']);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
