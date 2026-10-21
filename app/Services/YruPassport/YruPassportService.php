<?php

namespace App\Services\YruPassport;

use App\Exceptions\UpstreamServiceException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenID Connect client for YRU Passport (passport.yru.ac.th).
 *
 * Flow: redirect user to /oauth/authorize → receive ?code → exchange for an
 * access token → GET /oidc/userinfo → create/update the local user.
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
     * Exchange an authorization code for a YRU Passport access token.
     */
    public function exchangeCode(string $code): string
    {
        try {
            $response = $this->http()
                ->asForm()
                ->post($this->config['token_path'], [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'redirect_uri' => $this->config['redirect_uri'],
                    'code' => $code,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new UpstreamServiceException('yru_passport', 'ไม่สามารถยืนยันตัวตนกับ YRU Passport ได้', 401, $e);
        }

        return (string) $response->json('access_token');
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
            'name' => $name !== '' ? $name : $sub,
            'email' => $email,
            'passport_profile' => $claims,
            'last_login_at' => now(),
        ]);

        if ($emailVerified && $user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

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
