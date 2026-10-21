<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $user = $passport->syncUser($passport->getUserInfo($request->string('access_token')->toString()));

        return response()->json(self::issueToken($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * @return array{token: string, token_type: string, expires_at: string, user: User}
     */
    public static function issueToken(User $user): array
    {
        $expiresAt = now()->addDays(config('chat.token_ttl_days'));

        return [
            'token' => $user->createToken('yru-passport', ['*'], $expiresAt)->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $user,
        ];
    }
}
