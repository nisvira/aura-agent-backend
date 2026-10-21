<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ChatMessage */
class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'role' => $this->role,
            'content' => $this->content,
            'sources' => $this->metadata['sources'] ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
