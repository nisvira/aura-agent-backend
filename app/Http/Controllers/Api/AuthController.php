<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\YruPassport\YruPassportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    /**
     * POST /api/auth/yru-passport — for clients that already hold a YRU Passport
     * access token (e.g. a SPA/mobile app doing the OAuth flow itself).
     * The token is verified against /apis/v1/profile and swapped for a Sanctum token.
     */
    public function exchangePassportToken(Request $request, YruPassportService $passport): JsonResponse
    {
        $request->validate(['access_token' => ['required', 'string']]);

        try {
            $accessToken = $request->string('access_token')->toString();
            $user = $passport->syncUser($passport->getUserInfo($accessToken));
            $passport->storeTokens($user, ['access_token' => $accessToken]);
        } catch (UpstreamServiceException $e) {
            AuditLog::record('auth.login.failed', metadata: ['method' => 'passport_token', 'reason' => $e->getMessage()]);

            throw $e;
        }

        AuditLog::record('auth.login.success', $user, metadata: ['method' => 'passport_token']);

        return response()->json(self::issueToken($user));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            ...$user->toArray(),
            // admin | instructor | student | staff | null (no Passport login)
            'role' => $user->effectiveRole()?->value,
        ]);
    }

    /**
     * POST /api/auth/logout — revokes this API token (204).
     * With {"passport": true} also forgets the user's YRU Passport tokens and
     * returns the Passport logout page for the frontend to open (200).
     */
    public function logout(Request $request, YruPassportService $passport): Response|JsonResponse
    {
        $user = $request->user();
        $fromPassport = $request->boolean('passport');

        AuditLog::record('auth.logout', $user, metadata: ['method' => 'token', 'passport' => $fromPassport]);

        $user->currentAccessToken()->delete();

        if (! $fromPassport) {
            return response()->noContent();
        }

        $passport->forgetTokens($user);

        return response()->json(['passport_logout_url' => config('services.yru_passport.logout_url')]);
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string, user: User}
     */
    public static function issueToken(User $user): array
    {
        $expiresAt = now()->addDays(config('chat.token_ttl_days'));
        $token = $user->createToken('yru-passport', ['*'], $expiresAt);

        AuditLog::record('auth.token.issued', $user, metadata: ['token_id' => $token->accessToken->id, 'expires_at' => $expiresAt->toIso8601String()]);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $user,
        ];
    }
}
