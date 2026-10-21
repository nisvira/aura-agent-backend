<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'metadata'])]
class ChatMessage extends Model
{
    /**
     * Adding a message bumps the session's updated_at so the newest chats sort first.
     *
     * @var list<string>
     */
    protected $touches = ['session'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'metadata' => 'array',
        ];
    }
}
