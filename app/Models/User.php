<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['passport_id', 'username', 'name', 'email', 'password', 'passport_profile', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'passport_profile'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /**
     * Admins can use the test console. Set per user (app:create-admin) or by
     * email in ADMIN_EMAILS, which also covers YRU Passport accounts.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin || ($this->email && in_array(strtolower($this->email), config('auth.admin_emails'), true));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
            'passport_profile' => 'array',
        ];
    }
}
