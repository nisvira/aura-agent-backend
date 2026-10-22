<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Exceptions\UpstreamServiceException;
use App\Models\User;
use App\Services\YruPassport\YruPassportService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth for the OpenAI-compatible endpoints. Accepts, in order:
 *  1. The shared OPENWEBUI_API_KEY as Bearer + X-OpenWebUI-User-* headers
 *     (Open WebUI with ENABLE_FORWARD_USER_INFO_HEADERS=true),
 *  2. A personal Sanctum token, for scripts or other OpenAI clients,
 *  3. The user's own YRU Passport access token (Open WebUI logged in through
 *     Passport, connection auth "OAuth") — kept so students reach AURA-AGENT, or
 *  4. The shared key in the X-OpenWebUI-Key header + X-OpenWebUI-User-* headers.
 *     With connection auth "OAuth", Open WebUI sends no Authorization at all for
 *     accounts without Passport (e.g. local admins); this static custom header
 *     lets those accounts in, answered by Ollama.
 */
class AuthenticateOpenWebUi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $serviceKey = (string) config('openwebui.api_key');

        if ($serviceKey !== '' && $token !== '' && hash_equals($serviceKey, $token)) {
            $user = $this->userFromForwardedHeaders($request);

            if ($user instanceof JsonResponse) {
                return $user;
            }
        } else {
            $user = Auth::guard('sanctum')->user() ?? $this->userFromPassportToken($token);

            $headerKey = (string) $request->header('X-OpenWebUI-Key');

            if (! $user && $serviceKey !== '' && $headerKey !== '' && hash_equals($serviceKey, $headerKey)) {
                $user = $this->userFromForwardedHeaders($request);

                if ($user instanceof JsonResponse) {
                    return $user;
                }
            }
        }

        if (! $user) {
            if ($token !== '') {
                AuditLog::record('auth.invalid_api_key', metadata: ['path' => '/'.$request->path()]);
            }

            return self::error('Invalid API key', 'invalid_api_key', 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Map the Open WebUI account to a local user by email, so someone who also
     * logs in through YRU Passport sees a single chat history.
     */
    private function userFromForwardedHeaders(Request $request): User|JsonResponse
    {
        $email = Str::lower(trim((string) $request->header('X-OpenWebUI-User-Email')));

        if ($email === '') {
            return self::error(
                'Missing X-OpenWebUI-User-Email. Set ENABLE_FORWARD_USER_INFO_HEADERS=true in Open WebUI.',
                'missing_user_headers',
                401,
            );
        }

        $domains = config('openwebui.allowed_email_domains');
        $isAdmin = User::where('email', $email)->first()?->isAdmin() ?? in_array($email, config('auth.admin_emails'), true);

        // Admins may use any email domain (local accounts without YRU Passport).
        if ($domains !== [] && ! $isAdmin && ! in_array(Str::after($email, '@'), $domains, true)) {
            return self::error('This account is not allowed to use this model', 'forbidden_domain', 403);
        }

        $user = User::firstOrCreate(['email' => $email], [
            'passport_id' => 'openwebui:'.($request->header('X-OpenWebUI-User-Id') ?: $email),
            // Open WebUI URL-encodes the name so Thai characters survive the header.
            'name' => rawurldecode((string) $request->header('X-OpenWebUI-User-Name')) ?: $email,
        ]);

        if ($user->wasRecentlyCreated) {
            AuditLog::record('user.created', $user, $user, ['source' => 'openwebui']);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $user;
    }

    /**
     * Passport access tokens are JWTs (three dot-separated parts); Sanctum's are "id|secret".
     * userinfo is cached for 5 minutes so each chat message doesn't hit Passport.
     */
    private function userFromPassportToken(string $token): ?User
    {
        if (substr_count($token, '.') !== 2) {
            return null;
        }

        $passport = app(YruPassportService::class);

        try {
            $claims = Cache::remember('passport:userinfo:'.hash('sha256', $token), 300, fn () => $passport->getUserInfo($token));
        } catch (UpstreamServiceException) {
            return null;
        }

        $user = $passport->syncUser($claims);

        if ($user->passport_access_token !== $token) {
            $passport->storeTokens($user, ['access_token' => $token]);
        }

        return $user;
    }

    public static function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['message' => $message, 'type' => 'invalid_request_error', 'code' => $code],
        ], $status);
    }
}
