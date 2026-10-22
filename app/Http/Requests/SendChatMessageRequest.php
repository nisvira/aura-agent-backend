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
            // Admins / instructors asking about one student (checked by the "view-student" gate).
            'act_as_student' => ['nullable', 'digits:9'],
            'reason' => ['required_with:act_as_student', 'nullable', 'string', 'max:200'],
        ];
    }

    public function actAsStudent(): ?string
    {
        return $this->filled('act_as_student') ? $this->string('act_as_student')->toString() : null;
    }

    public function sessionId(): ?int
    {
        return $this->filled('session_id') ? $this->integer('session_id') : null;
    }
}
