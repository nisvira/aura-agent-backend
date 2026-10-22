<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['passport_id', 'username', 'role', 'name', 'email', 'password', 'passport_profile', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'passport_profile', 'passport_access_token', 'passport_refresh_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /**
     * Same rule AURA-AGENT applies (user_type claim from YRU Passport).
     */
    public function isStudent(): bool
    {
        return data_get($this->passport_profile, 'user_type') === 'student';
    }

    public function isInstructor(): bool
    {
        return $this->role === UserRole::Instructor;
    }

    /**
     * The role that decides what this user may see. Admin (from config) wins
     * over the Passport role; null = no Passport login (e.g. a local account).
     */
    public function effectiveRole(): ?UserRole
    {
        return $this->isAdmin() ? UserRole::Admin : $this->role;
    }

    /**
     * Passport role from userinfo claims: student, instructor (staff with an
     * academic title or a teaching position) or other staff.
     */
    public static function roleFromClaims(array $claims): ?UserRole
    {
        $isInstructor = preg_match(config('auth.instructor_prefix_pattern'), trim((string) ($claims['prefix_academic'] ?? '')))
            || preg_match(config('auth.instructor_position_pattern'), (string) ($claims['position_name'] ?? ''));

        return match ($claims['user_type'] ?? null) {
            'student' => UserRole::Student,
            'staff' => $isInstructor ? UserRole::Instructor : UserRole::Staff,
            default => null,
        };
    }

    /**
     * Admins can use the test console. Set per user (app:create-admin), by
     * email in ADMIN_EMAILS, or by YRU Passport username in ADMIN_USERNAMES.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin
            || ($this->email && in_array(strtolower($this->email), config('auth.admin_emails'), true))
            || ($this->username && in_array(strtolower($this->username), config('auth.admin_usernames'), true));
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
            'role' => UserRole::class,
            'password' => 'hashed',
            'passport_profile' => 'array',
            'passport_access_token' => 'encrypted',
            'passport_refresh_token' => 'encrypted',
            'passport_token_expires_at' => 'datetime',
        ];
    }
}
