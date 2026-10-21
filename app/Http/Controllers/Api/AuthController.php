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
            $user = $passport->syncUser($passport->getUserInfo($request->string('access_token')->toString()));
        } catch (UpstreamServiceException $e) {
            AuditLog::record('auth.login.failed', metadata: ['method' => 'passport_token', 'reason' => $e->getMessage()]);

            throw $e;
        }

        AuditLog::record('auth.login.success', $user, metadata: ['method' => 'passport_token']);

        return response()->json(self::issueToken($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): Response
    {
        AuditLog::record('auth.logout', $request->user(), metadata: ['method' => 'token']);

        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
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
