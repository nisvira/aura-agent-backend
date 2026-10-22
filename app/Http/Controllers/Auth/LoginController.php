<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\YruPassport\YruPassportService;
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

        $this->endSession($request);

        return redirect()->route('login');
    }

    /**
     * Log out here and from YRU Passport: forget the Passport tokens kept for
     * AURA-AGENT, then send the browser to Passport's logout page so the next
     * "login with YRU Passport" asks for a password again (shared computers).
     */
    public function logoutPassport(Request $request, YruPassportService $passport): RedirectResponse
    {
        AuditLog::record('auth.logout', $request->user(), metadata: ['method' => 'session', 'passport' => true]);

        $passport->forgetTokens($request->user());
        $this->endSession($request);

        return redirect()->away(config('services.yru_passport.logout_url'));
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
