<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Security-relevant events: logins, logouts, token issuance, admin grants,
 * deletions, 403s and 429s. Written through AuditLog::record().
 */
class AuditLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * Never throws: a failed audit write is logged, the request carries on.
     */
    public static function record(string $event, ?User $user = null, ?Model $target = null, array $metadata = []): ?self
    {
        try {
            $request = app()->runningInConsole() ? null : request();

            return static::create([
                'request_id' => Context::get('request_id'),
                'user_id' => $user?->getKey() ?? $request?->user()?->getKey(),
                'event' => $event,
                'target_type' => $target ? Str::snake(class_basename($target)) : null,
                'target_id' => $target?->getKey(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'metadata' => $metadata ?: null,
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log write failed', ['event' => $event, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('logging.retention.audit_days')));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
