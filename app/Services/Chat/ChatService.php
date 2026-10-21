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

/**
 * Chat pipeline: question → RAG search → prompt → Ollama → save both messages.
 *
 * Messages are only written after the LLM answers, so a failed call never
 * leaves a dangling user message (or an empty new session) behind.
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
     * @return array{session: ChatSession, message: ChatMessage}
     */
    public function reply(User $user, string $question, ?int $sessionId = null): array
    {
        $session = $this->resolveSession($user, $sessionId, $question);

        return $this->replyWithHistory($session, $question, $this->storedHistory($session));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(User $user, string $question, ?int $sessionId = null): Generator
    {
        $session = $this->resolveSession($user, $sessionId, $question);

        yield from $this->streamWithHistory($session, $question, $this->storedHistory($session));
    }

    /**
     * Answer using a history supplied by the caller (Open WebUI sends the whole
     * conversation on every request). The new turn is still saved to $session.
     *
     * @param  list<array{role: string, content: string}>  $history  oldest first
     * @return array{session: ChatSession, message: ChatMessage}
     */
    public function replyWithHistory(ChatSession $session, string $question, array $history): array
    {
        $chunks = $this->rag->search($question);

        $answer = $this->llm->chat($this->prompts->build($question, $chunks, $this->trim($history)));

        return $this->persist($session, $question, $answer['content'], $chunks, $answer['stats']);
    }

    /**
     * Streaming variant: yields a "sources" event, then ['type' => 'delta', 'content' => ...]
     * while the model is generating, then a final "done" event after saving.
     *
     * @param  list<array{role: string, content: string}>  $history  oldest first
     * @return Generator<int, array<string, mixed>>
     */
    public function streamWithHistory(ChatSession $session, string $question, array $history): Generator
    {
        $chunks = $this->rag->search($question);

        yield ['type' => 'sources', 'sources' => array_map(fn (RagChunk $c) => $c->toArray(), $chunks)];

        $tokens = $this->llm->chatStream($this->prompts->build($question, $chunks, $this->trim($history)));
        $content = '';

        foreach ($tokens as $delta) {
            $content .= $delta;
            yield ['type' => 'delta', 'content' => $delta];
        }

        $stats = $tokens->getReturn() ?? [];
        $result = $this->persist($session, $question, $content, $chunks, $stats);

        yield [
            'type' => 'done',
            'session_id' => $result['session']->id,
            'message_id' => $result['message']->id,
            'usage' => [
                'prompt_tokens' => $stats['prompt_tokens'] ?? null,
                'completion_tokens' => $stats['completion_tokens'] ?? null,
            ],
        ];
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
     * @return array{session: ChatSession, message: ChatMessage}
     */
    private function persist(ChatSession $session, string $question, string $answer, array $chunks, array $stats): array
    {
        return DB::transaction(function () use ($session, $question, $answer, $chunks, $stats) {
            $session->save();

            $session->messages()->create([
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

            return ['session' => $session, 'message' => $message];
        });
    }
}
