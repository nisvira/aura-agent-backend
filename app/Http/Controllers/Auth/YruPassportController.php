<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Controller;
use App\Services\YruPassport\YruPassportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Browser-based login (Authorization Code flow):
 *   GET /auth/yru/redirect → passport.yru.ac.th → GET /auth/yru/callback
 *   → redirect to the frontend with the Sanctum token in the URL fragment.
 */
class YruPassportController extends Controller
{
    public function __construct(private readonly YruPassportService $passport) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('yru_passport_state', $state);

        return redirect()->away($this->passport->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('yru_passport_state');

        abort_if(! $expected || ! hash_equals($expected, (string) $request->query('state')), 403, 'Invalid OAuth state');
        abort_if($request->filled('error') || ! $request->filled('code'), 401, 'YRU Passport login was cancelled');

        $accessToken = $this->passport->exchangeCode($request->query('code'));
        $user = $this->passport->syncUser($this->passport->getUserInfo($accessToken));

        $auth = AuthController::issueToken($user);

        // Fragment (#) is never sent to servers or logged by proxies, unlike a query string.
        return redirect()->away(config('chat.frontend_callback_url').'#'.http_build_query([
            'token' => $auth['token'],
            'expires_at' => $auth['expires_at'],
        ]));
    }
}
