<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * OpenAI-style POST /v1/chat/completions body, as sent by Open WebUI.
 */
class ChatCompletionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string'],
            'stream' => ['nullable', 'boolean'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string'],
            'messages.*.content' => ['present'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $last = Arr::last($this->conversation());

                if (($last['role'] ?? null) !== 'user') {
                    $validator->errors()->add('messages', 'The last message must come from the user.');
                } elseif (mb_strlen($last['content']) > config('chat.max_message_length') && ! $this->isTask()) {
                    $validator->errors()->add('messages', 'The message is too long.');
                }
            },
        ];
    }

    /**
     * Messages with content flattened to text (image parts are dropped).
     *
     * @return list<array{role: string, content: string}>
     */
    public function conversation(): array
    {
        return collect($this->input('messages', []))
            ->filter(fn ($m) => is_array($m) && isset($m['role']))
            ->map(fn (array $m) => ['role' => (string) $m['role'], 'content' => self::text($m['content'] ?? '')])
            ->values()
            ->all();
    }

    public function question(): string
    {
        return Arr::last($this->conversation())['content'];
    }

    /**
     * Previous user/assistant turns. Client system prompts are dropped so the
     * university system prompt in config/chat.php always applies.
     *
     * @return list<array{role: string, content: string}>
     */
    public function history(): array
    {
        return collect($this->conversation())
            ->slice(0, -1)
            ->filter(fn (array $m) => in_array($m['role'], ['user', 'assistant'], true) && $m['content'] !== '')
            ->values()
            ->all();
    }

    public function firstQuestion(): string
    {
        return collect($this->conversation())->firstWhere('role', 'user')['content'] ?? $this->question();
    }

    /**
     * Open WebUI background jobs (title, tags, follow-ups, autocomplete...).
     * These go straight to Ollama: no RAG, no history saved.
     */
    public function isTask(): bool
    {
        return self::isTaskPayload($this->header('X-OpenWebUI-Task'), $this->input('messages', []));
    }

    /**
     * Preferred signal: the X-OpenWebUI-Task header (set as a custom connection
     * header with value "{{TASK}}"). Fallback: Open WebUI's task prompts start
     * with "### Task:"; its own RAG template does too but contains <context>.
     */
    public static function isTaskPayload(?string $taskHeader, mixed $messages): bool
    {
        if ($taskHeader !== null) {
            return $taskHeader !== '';
        }

        $last = is_array($messages) ? Arr::last($messages) : null;
        $text = is_array($last) ? self::text($last['content'] ?? '') : '';

        return Str::startsWith(ltrim($text), '### Task:') && ! Str::contains($text, '<context>');
    }

    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            return collect($content)
                ->filter(fn ($part) => is_array($part) && ($part['type'] ?? null) === 'text')
                ->pluck('text')
                ->implode("\n");
        }

        return '';
    }
}
