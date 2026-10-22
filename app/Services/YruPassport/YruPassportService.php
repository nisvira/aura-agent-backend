<?php

namespace App\Services\YruPassport;

use App\Exceptions\UpstreamServiceException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * OpenID Connect client for YRU Passport (passport.yru.ac.th).
 *
 * Flow: redirect user to /oauth/authorize → receive ?code → exchange for
 * tokens → GET /oidc/userinfo → create/update the local user and keep the
 * tokens for AURA-AGENT.
 */
class YruPassportService
{
    public function __construct(private readonly array $config) {}

    public function authorizeUrl(string $state): string
    {
        return rtrim($this->config['base_url'], '/').$this->config['authorize_path'].'?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->config['scopes'],
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorization code for YRU Passport tokens.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code,
        ], 'ไม่สามารถยืนยันตัวตนกับ YRU Passport ได้');
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => $this->config['scopes'],
        ], 'YRU Passport session หมดอายุ กรุณาเข้าสู่ระบบใหม่');
    }

    /**
     * Keep the user's Passport tokens (encrypted at rest) so the backend can call
     * AURA-AGENT on their behalf. A response without a refresh token keeps the old one.
     */
    public function storeTokens(User $user, array $tokens): void
    {
        $access = (string) ($tokens['access_token'] ?? '');

        $user->forceFill([
            'passport_access_token' => $access,
            'passport_refresh_token' => $tokens['refresh_token'] ?? $user->passport_refresh_token,
            'passport_token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : $this->jwtExpiry($access),
        ])->save();
    }

    /**
     * A Passport access token for this user that is still valid for at least a
     * minute, refreshed when needed. Null when there is none (password login,
     * Open WebUI header login) or the refresh failed — the user must log in again.
     */
    public function accessTokenFor(User $user): ?string
    {
        if (! $user->passport_access_token) {
            return null;
        }

        $expiresAt = $user->passport_token_expires_at;

        if ($expiresAt === null || $expiresAt->isAfter(now()->addMinute())) {
            return $user->passport_access_token;
        }

        if (! $user->passport_refresh_token) {
            return null;
        }

        try {
            $this->storeTokens($user, $this->refresh($user->passport_refresh_token));
        } catch (UpstreamServiceException) {
            $this->forgetTokens($user);

            return null;
        }

        return $user->passport_access_token;
    }

    public function forgetTokens(User $user): void
    {
        $user->forceFill([
            'passport_access_token' => null,
            'passport_refresh_token' => null,
            'passport_token_expires_at' => null,
        ])->save();
    }

    /**
     * The `exp` claim of a JWT access token, read without verifying it (only used
     * to know when to refresh; Passport itself validates the token on every use).
     */
    private function jwtExpiry(string $token): ?Carbon
    {
        $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[1] ?? '', '-_', '+/')), true);

        return isset($payload['exp']) ? Carbon::createFromTimestamp((int) $payload['exp']) : null;
    }

    private function tokenRequest(array $params, string $failureMessage): array
    {
        try {
            $response = $this->http()
                ->asForm()
                ->post($this->config['token_path'], [
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    ...$params,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new UpstreamServiceException('yru_passport', $failureMessage, 401, $e);
        }

        return $response->json();
    }

    /**
     * Fetch the OIDC claims of the access token's owner (GET /oidc/userinfo).
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            return $this->http()
                ->withToken($accessToken)
                ->get($this->config['profile_path'])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            throw new UpstreamServiceException('yru_passport', 'Token ของ YRU Passport ไม่ถูกต้องหรือหมดอายุ', 401, $e);
        }
    }

    /**
     * Create or update the local user from the userinfo claims.
     *
     * Claims: sub (always); preferred_username, name, display_name, firstname,
     * lastname, user_type, org_unit_*, program_* (profile scope); email,
     * email_verified (email scope).
     */
    public function syncUser(array $claims): User
    {
        $sub = $this->first($claims, ['sub']);

        if ($sub === null) {
            throw new UpstreamServiceException('yru_passport', 'ข้อมูลผู้ใช้จาก YRU Passport ไม่ครบถ้วน', 502);
        }

        $name = $this->first($claims, ['display_name', 'name', 'fullname_with_prefix'])
            ?? trim(($claims['firstname'] ?? $claims['given_name'] ?? '').' '.($claims['lastname'] ?? $claims['family_name'] ?? ''));

        $email = ($email = $this->first($claims, ['email'])) ? Str::lower($email) : null;
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        // Link to an account created earlier through Open WebUI, but only by a verified
        // email, so an unverified address can't take over someone else's history.
        $user = User::where('passport_id', $sub)->first()
            ?? ($email && $emailVerified ? User::where('email', $email)->first() : null)
            ?? new User;

        $user->fill([
            'passport_id' => $sub,
            'username' => $this->first($claims, ['preferred_username', 'username']),
            'role' => User::roleFromClaims($claims),
            'name' => $name !== '' ? $name : $sub,
            'email' => $email,
            'passport_profile' => $claims,
            'last_login_at' => now(),
        ]);

        if ($emailVerified && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $isNew = ! $user->exists;
        $user->save();

        if ($isNew) {
            AuditLog::record('user.created', $user, $user, ['source' => 'passport']);
        }

        return $user;
    }

    private function first(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($value = Arr::get($data, $key))) {
                return (string) $value;
            }
        }

        return null;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->acceptJson()
            ->timeout($this->config['timeout']);
    }
}
