<?php

namespace App\Services\Chat;

use App\Models\AiRequest;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collects timings for one question and writes a single ai_requests row.
 */
final class AiTrace
{
    private readonly int $startedAt;

    private ?int $llmStartedAt = null;

    private bool $finished = false;

    private array $data;

    private function __construct(array $data)
    {
        $this->startedAt = hrtime(true);
        $this->data = $data;
    }

    public static function start(string $channel, ?int $userId, string $question, bool $isTask = false): self
    {
        return new self([
            'request_id' => Context::get('request_id'),
            'user_id' => $userId,
            'channel' => $channel,
            'is_task' => $isTask,
            'question_chars' => mb_strlen($question),
        ]);
    }

    public function rag(int $ms, int $hits, ?float $topScore, ?string $error): void
    {
        $this->data += [
            'rag_status' => $error === null ? 'ok' : 'error',
            'rag_ms' => $ms,
            'rag_hits' => $hits,
            'rag_top_score' => $topScore,
        ];
    }

    public function llmStarted(string $model): void
    {
        $this->llmStartedAt = hrtime(true);
        $this->data['model'] = $model;
    }

    public function firstToken(): void
    {
        $this->data['ttft_ms'] ??= self::msSince($this->startedAt);
    }

    /**
     * @param  array{prompt_tokens?: int|null, completion_tokens?: int|null}  $stats
     */
    public function llmFinished(array $stats): void
    {
        $this->data['llm_ms'] = $this->llmStartedAt ? self::msSince($this->llmStartedAt) : null;
        $this->data['prompt_tokens'] = $stats['prompt_tokens'] ?? null;
        $this->data['completion_tokens'] = $stats['completion_tokens'] ?? null;
    }

    /**
     * Write the row. Safe to call more than once (only the first call counts)
     * and never throws, so logging can't break a chat.
     */
    public function finish(
        string $status,
        ?ChatSession $session = null,
        ?ChatMessage $question = null,
        ?ChatMessage $answer = null,
        ?string $error = null,
    ): void {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        try {
            AiRequest::create([
                ...$this->data,
                'status' => $status,
                'session_id' => $session?->exists ? $session->id : null,
                'question_message_id' => $question?->id,
                'answer_message_id' => $answer?->id,
                'total_ms' => self::msSince($this->startedAt),
                'error' => $error ? Str::limit($error, 490) : null,
            ]);
        } catch (Throwable $e) {
            Log::error('AI request log write failed', ['error' => $e->getMessage()]);
        }
    }

    private static function msSince(int $hrtime): int
    {
        return intdiv(hrtime(true) - $hrtime, 1_000_000);
    }
}
