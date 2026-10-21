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
 * OAuth2 client for YRU Passport (passport.yru.ac.th).
 *
 * Flow: redirect user to /oauth/authorize → receive ?code → exchange for an
 * access token → GET /apis/v1/profile → create/update the local user.
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
     * Fetch the profile of the access token's owner. Equivalent to the original
     * Guzzle getUserInfo(), but via Laravel's HTTP client so it can be faked in tests.
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
     * Create or update the local user from a YRU Passport profile.
     *
     * TODO: confirm the real field names returned by /apis/v1/profile; the
     * fallbacks below cover the common shapes ({...} or {"data": {...}}).
     */
    public function syncUser(array $profile): User
    {
        $data = Arr::get($profile, 'data', $profile);

        $passportId = $this->first($data, ['id', 'user_id', 'sub', 'username']);

        if ($passportId === null) {
            throw new UpstreamServiceException('yru_passport', 'ข้อมูลผู้ใช้จาก YRU Passport ไม่ครบถ้วน', 502);
        }

        $name = $this->first($data, ['name', 'fullname', 'full_name', 'display_name'])
            ?? trim(($data['first_name'] ?? $data['firstname'] ?? '').' '.($data['last_name'] ?? $data['lastname'] ?? ''));

        $email = ($email = $this->first($data, ['email', 'mail'])) ? Str::lower($email) : null;

        // Match by email too: the account may already exist from an Open WebUI login.
        $user = User::where('passport_id', (string) $passportId)->first()
            ?? ($email ? User::where('email', $email)->first() : null)
            ?? new User;

        $user->fill([
            'passport_id' => (string) $passportId,
            'username' => $this->first($data, ['username', 'user_name', 'login']),
            'name' => $name !== '' ? $name : (string) $passportId,
            'email' => $email,
            'passport_profile' => $data,
            'last_login_at' => now(),
        ])->save();

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
