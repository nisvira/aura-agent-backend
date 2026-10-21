<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per question sent to the LLM: RAG and LLM timings, token usage and
 * outcome. Written by App\Services\Chat\AiTrace.
 */
class AiRequest extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'question_message_id');
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('logging.retention.ai_request_days')));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_task' => 'boolean',
            'rag_top_score' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
