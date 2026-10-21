<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendChatMessageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Ownership of session_id is enforced in ChatService (404 for other users' sessions).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('chat.max_message_length')],
            'session_id' => ['nullable', 'integer'],
        ];
    }

    public function sessionId(): ?int
    {
        return $this->filled('session_id') ? $this->integer('session_id') : null;
    }
}
