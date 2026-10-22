<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Controller;
use App\Exceptions\UpstreamServiceException;
use App\Models\AuditLog;
use App\Services\YruPassport\YruPassportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Browser-based login (Authorization Code flow):
 *   GET /auth/yru/redirect → passport.yru.ac.th → GET /auth/yru/callback
 *   → redirect to the frontend with the Sanctum token in the URL fragment,
 *   or, when started with ?to=console, log into the test console session.
 */
class YruPassportController extends Controller
{
    public function __construct(private readonly YruPassportService $passport) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('yru_passport_state', $state);
        $request->session()->put('yru_passport_to', $request->query('to') === 'console' ? 'console' : 'frontend');

        return redirect()->away($this->passport->authorizeUrl($state));
    }

    /**
     * The login page on the same host as the registered Passport callback;
     * only there does the session cookie survive the round trip.
     */
    public static function loginUrl(): string
    {
        $callback = parse_url((string) config('services.yru_passport.redirect_uri'));

        return ($callback['scheme'] ?? 'http').'://'.($callback['host'] ?? '127.0.0.1')
            .(isset($callback['port']) ? ':'.$callback['port'] : '').'/login';
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('yru_passport_state');

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            // Usually not an attack: login was started on another address than the
            // registered callback (e.g. 10.20.41.54:8000 vs 127.0.0.1), so this
            // browser has no session cookie here. Send them back with directions.
            AuditLog::record('auth.state_mismatch', metadata: ['method' => 'passport']);

            return redirect()->route('login')->with('status', 'เข้าสู่ระบบไม่สำเร็จ กรุณาเริ่มใหม่จาก '.self::loginUrl().' แล้วกด "เข้าสู่ระบบด้วย YRU Passport" อีกครั้ง');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            AuditLog::record('auth.login.failed', metadata: ['method' => 'passport', 'reason' => (string) $request->query('error', 'no_code')]);
            abort(401, 'YRU Passport login was cancelled');
        }

        try {
            $tokens = $this->passport->exchangeCode($request->query('code'));
            $user = $this->passport->syncUser($this->passport->getUserInfo($tokens['access_token']));
            $this->passport->storeTokens($user, $tokens);
        } catch (UpstreamServiceException $e) {
            AuditLog::record('auth.login.failed', metadata: ['method' => 'passport', 'reason' => $e->getMessage()]);

            throw $e;
        }

        $to = $request->session()->pull('yru_passport_to');
        AuditLog::record('auth.login.success', $user, metadata: ['method' => 'passport', 'to' => $to]);

        if ($to === 'console') {
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended(route('console'));
        }

        $auth = AuthController::issueToken($user);

        // Fragment (#) is never sent to servers or logged by proxies, unlike a query string.
        return redirect()->away(config('chat.frontend_callback_url').'#'.http_build_query([
            'token' => $auth['token'],
            'expires_at' => $auth['expires_at'],
        ]));
    }
}
