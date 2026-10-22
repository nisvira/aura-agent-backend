<?php

namespace App\Services\Chat;

use App\Enums\MessageRole;
use App\Exceptions\UpstreamServiceException;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Agent\ActAs;
use App\Services\Agent\AuraAgentClient;
use App\Services\YruPassport\YruPassportService;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chat pipeline: every answer comes from AURA-AGENT, which reads the
 * student's real data from the Activity API and keeps its own memory of
 * each conversation (thread_id). Laravel authenticates the student, passes
 * on their YRU Passport token, streams the answer through and keeps a copy
 * of the conversation for the history screens.
 *
 * Messages are only written after an answer is complete, and every question
 * leaves one ai_requests row (AiTrace).
 */
class ChatService
{
    public const PASSPORT_REQUIRED = 'กรุณาเข้าสู่ระบบด้วย YRU Passport ก่อนใช้งาน AURA';

    public const SUPPORT_UNAVAILABLE = 'ยังถามแทนนักศึกษาไม่ได้ ต้องรอให้ AURA-AGENT เปิดโหมดถามแทน (AURA_AGENT_SUPPORT_KEY)';

    public function __construct(
        private readonly AuraAgentClient $agent,
        private readonly YruPassportService $passport,
    ) {}

    /**
     * Wait for the whole answer. With $actAs (already authorised by the
     * "view-student" gate) an admin or instructor asks about that student.
     *
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    public function reply(User $user, string $question, ?int $sessionId = null, string $channel = 'api', ?ActAs $actAs = null): array
    {
        $session = $this->resolveSession($user, $sessionId, $question, $actAs);

        return $this->drain($this->run($session, $question, $channel, $actAs));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(User $user, string $question, ?int $sessionId = null, string $channel = 'api_stream', ?ActAs $actAs = null): Generator
    {
        $session = $this->resolveSession($user, $sessionId, $question, $actAs);

        yield from $this->run($session, $question, $channel, $actAs);
    }

    /**
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    public function replyInSession(ChatSession $session, string $question, string $channel = 'openwebui'): array
    {
        return $this->drain($this->run($session, $question, $channel));
    }

    /**
     * Wait for a run to finish; the events only matter when streaming.
     */
    private function drain(Generator $run): array
    {
        foreach ($run as $event) {
            // discard
        }

        return $run->getReturn();
    }

    /**
     * Stream the answer: "status" while the agent looks something up, "delta"
     * chunks of text, then "done" once saved. Problems are thrown as
     * UpstreamServiceException (errorCode: PASSPORT_REQUIRED, NOT_STUDENT,
     * TOKEN_EXPIRED, UPSTREAM_UNAVAILABLE, LLM_UNAVAILABLE, ...).
     * If the consumer stops early (client disconnected) the trace is saved as "aborted".
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function streamInSession(ChatSession $session, string $question, string $channel = 'openwebui'): Generator
    {
        yield from $this->run($session, $question, $channel);
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
     * @return Generator<int, array<string, mixed>, mixed, array{session: ChatSession, question: ChatMessage, message: ChatMessage}>
     */
    private function run(ChatSession $session, string $question, string $channel, ?ActAs $actAs = null): Generator
    {
        $trace = AiTrace::start($channel, $session->user_id, $question, engine: 'aura_agent');
        $trace->actingAs($actAs?->studentCode);
        $session->agent_thread_id ??= (string) Str::uuid();
        $status = 'aborted';
        $error = null;
        $result = null;

        try {
            $token = $actAs ? $this->assertSupportMode() : $this->passportToken($session->user);

            $trace->llmStarted('aura-agent');
            $content = '';

            foreach ($this->agent->chat($token, $session->agent_thread_id, $question, $actAs) as $event) {
                $data = $event['data'];

                switch ($event['event']) {
                    case 'status':
                        yield ['type' => 'status', 'text' => (string) ($data['text'] ?? '')];
                        break;

                    case 'token':
                        $trace->firstToken();
                        $content .= $text = (string) ($data['text'] ?? '');
                        yield ['type' => 'delta', 'content' => $text];
                        break;

                    case 'error':
                        $code = (string) ($data['code'] ?? 'INTERNAL');

                        if ($code === 'TOKEN_EXPIRED') {
                            $this->passport->forgetTokens($session->user);
                        }

                        throw new UpstreamServiceException(
                            'aura_agent',
                            (string) ($data['text'] ?? AuraAgentClient::UNAVAILABLE),
                            match ($code) {
                                'TOKEN_EXPIRED' => 401,
                                'NOT_STUDENT' => 403,
                                default => 503,
                            },
                            errorCode: $code,
                        );

                    case 'done':
                        $stats = ['tools_used' => $data['tools_used'] ?? [], 'latency_ms' => $data['latency_ms'] ?? null];
                        $trace->llmFinished($stats);

                        $result = $this->persist($session, $question, $content, $stats);
                        $status = 'success';

                        yield [
                            'type' => 'done',
                            'session_id' => $result['session']->id,
                            'message_id' => $result['message']->id,
                            'acting_as' => $actAs?->studentCode,
                            ...$stats,
                        ];

                        return $result;
                }
            }

            throw new UpstreamServiceException('aura_agent', AuraAgentClient::UNAVAILABLE, 503, errorCode: 'INCOMPLETE');
        } catch (Throwable $e) {
            $status = 'error';
            $error = $e instanceof UpstreamServiceException && $e->errorCode ? "{$e->errorCode}: {$e->getMessage()}" : $e->getMessage();

            throw $e;
        } finally {
            $trace->finish($status, $result['session'] ?? $session, $result['question'] ?? null, $result['message'] ?? null, $error);
        }
    }

    /**
     * Support mode needs no Passport token (the agent looks the student up by code).
     */
    private function assertSupportMode(): null
    {
        if (! $this->agent->supportEnabled()) {
            throw new UpstreamServiceException('aura_agent', self::SUPPORT_UNAVAILABLE, 503, errorCode: 'SUPPORT_UNAVAILABLE');
        }

        return null;
    }

    /**
     * The user's YRU Passport access token. Students and staff both go to the
     * agent (since AURA-AGENT 670a21a staff can search activity rounds and are
     * told personal progress is for students only), so no role filter here.
     */
    private function passportToken(User $user): string
    {
        if (! $this->agent->enabled()) {
            throw new UpstreamServiceException('aura_agent', AuraAgentClient::UNAVAILABLE, 503, errorCode: 'AGENT_UNAVAILABLE');
        }

        return $this->passport->accessTokenFor($user)
            ?? throw new UpstreamServiceException('aura_agent', self::PASSPORT_REQUIRED, 401, errorCode: 'PASSPORT_REQUIRED');
    }

    /**
     * Load the user's existing session (404 if it belongs to someone else) or
     * prepare an unsaved new one titled after the first question. A session is
     * tied to one subject: the user themself, or the one student asked about.
     */
    private function resolveSession(User $user, ?int $sessionId, string $question, ?ActAs $actAs = null): ChatSession
    {
        if ($sessionId !== null) {
            $session = $user->chatSessions()->findOrFail($sessionId);

            abort_unless(
                $session->acting_as_student === $actAs?->studentCode,
                422,
                'ห้องแชทนี้เป็นของนักศึกษาคนอื่น กรุณาเริ่มแชทใหม่',
            );

            return $session;
        }

        $session = new ChatSession([
            'title' => Str::limit(($actAs ? "[{$actAs->studentCode}] " : '').Str::squish($question), 60),
            'acting_as_student' => $actAs?->studentCode,
        ]);
        $session->user()->associate($user);

        return $session;
    }

    /**
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    private function persist(ChatSession $session, string $question, string $answer, array $stats): array
    {
        return DB::transaction(function () use ($session, $question, $answer, $stats) {
            $session->save();

            $questionMessage = $session->messages()->create([
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $message = $session->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => $answer,
                'metadata' => ['engine' => 'aura_agent', ...$stats],
            ]);

            return ['session' => $session, 'question' => $questionMessage, 'message' => $message];
        });
    }
}
