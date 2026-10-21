<?php

namespace App\Services\Chat;

use App\Enums\MessageRole;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Llm\OllamaClient;
use App\Services\Rag\RagChunk;
use App\Services\Rag\RagClient;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chat pipeline: question → RAG search → prompt → Ollama → save both messages.
 *
 * Messages are only written after the LLM answers, so a failed call never
 * leaves a dangling user message (or an empty new session) behind. Every
 * question, successful or not, leaves one ai_requests row (AiTrace).
 */
class ChatService
{
    public function __construct(
        private readonly RagClient $rag,
        private readonly OllamaClient $llm,
        private readonly PromptBuilder $prompts,
        private readonly int $historyLimit,
    ) {}

    /**
     * Answer using the history stored in our database (our own frontend).
     *
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    public function reply(User $user, string $question, ?int $sessionId = null, string $channel = 'api'): array
    {
        $session = $this->resolveSession($user, $sessionId, $question);

        return $this->replyWithHistory($session, $question, $this->storedHistory($session), $channel);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(User $user, string $question, ?int $sessionId = null, string $channel = 'api_stream'): Generator
    {
        $session = $this->resolveSession($user, $sessionId, $question);

        yield from $this->streamWithHistory($session, $question, $this->storedHistory($session), $channel);
    }

    /**
     * Answer using a history supplied by the caller (Open WebUI sends the whole
     * conversation on every request). The new turn is still saved to $session.
     *
     * @param  list<array{role: string, content: string}>  $history  oldest first
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    public function replyWithHistory(ChatSession $session, string $question, array $history, string $channel = 'openwebui'): array
    {
        $trace = AiTrace::start($channel, $session->user_id, $question);

        try {
            $chunks = $this->retrieve($question, $trace);

            $trace->llmStarted($this->llm->model());
            $answer = $this->llm->chat($this->prompts->build($question, $chunks, $this->trim($history)));
            $trace->llmFinished($answer['stats']);

            $result = $this->persist($session, $question, $answer['content'], $chunks, $answer['stats']);
        } catch (Throwable $e) {
            $trace->finish('error', $session, error: $e->getMessage());

            throw $e;
        }

        $trace->finish('success', $result['session'], $result['question'], $result['message']);

        return $result;
    }

    /**
     * Streaming variant: yields a "sources" event, then ['type' => 'delta', 'content' => ...]
     * while the model is generating, then a final "done" event after saving.
     * If the consumer stops early (client disconnected) the trace is saved as "aborted".
     *
     * @param  list<array{role: string, content: string}>  $history  oldest first
     * @return Generator<int, array<string, mixed>>
     */
    public function streamWithHistory(ChatSession $session, string $question, array $history, string $channel = 'openwebui'): Generator
    {
        $trace = AiTrace::start($channel, $session->user_id, $question);
        $status = 'aborted';
        $error = null;
        $result = null;

        try {
            $chunks = $this->retrieve($question, $trace);

            yield ['type' => 'sources', 'sources' => array_map(fn (RagChunk $c) => $c->toArray(), $chunks)];

            $trace->llmStarted($this->llm->model());
            $tokens = $this->llm->chatStream($this->prompts->build($question, $chunks, $this->trim($history)));
            $content = '';

            foreach ($tokens as $delta) {
                $trace->firstToken();
                $content .= $delta;
                yield ['type' => 'delta', 'content' => $delta];
            }

            $stats = $tokens->getReturn() ?? [];
            $trace->llmFinished($stats);

            $result = $this->persist($session, $question, $content, $chunks, $stats);
            $status = 'success';

            yield [
                'type' => 'done',
                'session_id' => $result['session']->id,
                'message_id' => $result['message']->id,
                'usage' => [
                    'prompt_tokens' => $stats['prompt_tokens'] ?? null,
                    'completion_tokens' => $stats['completion_tokens'] ?? null,
                ],
            ];
        } catch (Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();

            throw $e;
        } finally {
            $trace->finish($status, $result['session'] ?? $session, $result['question'] ?? null, $result['message'] ?? null, $error);
        }
    }

    /**
     * Find (or prepare, unsaved) the session mirroring a chat that lives in an
     * external client such as Open WebUI.
     */
    public function sessionForExternalChat(User $user, string $externalId, string $firstQuestion): ChatSession
    {
        return $user->chatSessions()->firstOrNew(
            ['external_id' => $externalId],
            ['title' => Str::limit(Str::squish($firstQuestion), 60)],
        );
    }

    /**
     * Load the user's existing session (404 if it belongs to someone else) or
     * prepare an unsaved new one titled after the first question.
     */
    private function resolveSession(User $user, ?int $sessionId, string $question): ChatSession
    {
        if ($sessionId !== null) {
            return $user->chatSessions()->findOrFail($sessionId);
        }

        $session = new ChatSession(['title' => Str::limit(Str::squish($question), 60)]);
        $session->user()->associate($user);

        return $session;
    }

    /**
     * @return list<RagChunk>
     */
    private function retrieve(string $question, AiTrace $trace): array
    {
        $result = $this->rag->searchWithStatus($question);

        $trace->rag($result['ms'], count($result['chunks']), collect($result['chunks'])->max('score'), $result['error']);

        return $result['chunks'];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function storedHistory(ChatSession $session): array
    {
        if (! $session->exists) {
            return [];
        }

        return $session->messages()->latest('id')->limit($this->historyLimit)->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => ['role' => $m->role->value, 'content' => $m->content])
            ->values()
            ->all();
    }

    private function trim(array $history): array
    {
        return array_slice($history, -$this->historyLimit);
    }

    /**
     * @param  list<RagChunk>  $chunks
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    private function persist(ChatSession $session, string $question, string $answer, array $chunks, array $stats): array
    {
        return DB::transaction(function () use ($session, $question, $answer, $chunks, $stats) {
            $session->save();

            $questionMessage = $session->messages()->create([
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $message = $session->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => $answer,
                'metadata' => [
                    'sources' => array_map(fn (RagChunk $c) => $c->toArray(), $chunks),
                    ...$stats,
                ],
            ]);

            return ['session' => $session, 'question' => $questionMessage, 'message' => $message];
        });
    }
}
