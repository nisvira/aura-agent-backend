<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Concerns\LocksChatPerUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\AuditLog;
use App\Services\Agent\ActAs;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    use LocksChatPerUser;

    public function __construct(private readonly ChatService $chat) {}

    /**
     * POST /api/chat — ask a question and wait for the full answer.
     */
    public function store(SendChatMessageRequest $request): JsonResponse
    {
        $actAs = $this->actAs($request);
        $lock = $this->acquireUserLock($request->user()->id);

        try {
            $result = $this->chat->reply(
                $request->user(),
                $request->string('message')->toString(),
                $request->sessionId(),
                $this->isConsole($request) ? 'console' : 'api',
                $actAs,
            );
        } finally {
            $lock->release();
        }

        return response()->json([
            'session_id' => $result['session']->id,
            'title' => $result['session']->title,
            'message' => new ChatMessageResource($result['message']),
        ], 201);
    }

    /**
     * POST /api/chat/stream — same as store() but streams the answer as
     * Server-Sent Events: "status" (agent looking things up) / "sources" (RAG),
     * then many "delta", then "done" — or "error" {message, code}.
     */
    public function stream(SendChatMessageRequest $request): StreamedResponse
    {
        $user = $request->user();
        $question = $request->string('message')->toString();
        $sessionId = $request->sessionId();
        $channel = $this->isConsole($request) ? 'console' : 'api_stream';
        $actAs = $this->actAs($request);

        // Check ownership now: once the stream starts, headers are sent and a 404 is no longer possible.
        if ($sessionId !== null) {
            $user->chatSessions()->findOrFail($sessionId);
        }

        $lock = $this->acquireUserLock($user->id);

        return response()->eventStream(function () use ($lock, $user, $question, $sessionId, $channel, $actAs) {
            // Keep running after a client disconnect so the loop can stop cleanly and release the lock.
            ignore_user_abort(true);

            try {
                foreach ($this->chat->stream($user, $question, $sessionId, $channel, $actAs) as $event) {
                    yield new StreamedEvent(event: $event['type'], data: $event);
                }
            } catch (UpstreamServiceException $e) {
                yield new StreamedEvent(event: 'error', data: array_filter(['message' => $e->getMessage(), 'code' => $e->errorCode]));
            } finally {
                $lock->release();
            }
        }, headers: ['X-Accel-Buffering' => 'no']);
    }

    /**
     * act_as_student → an authorised ActAs (or 403), audited either way.
     * Asking about yourself is just a normal question.
     */
    private function actAs(SendChatMessageRequest $request): ?ActAs
    {
        $user = $request->user();
        $studentCode = $request->actAsStudent();

        if ($studentCode === null || $studentCode === $user->username) {
            return null;
        }

        if (Gate::forUser($user)->denies('view-student', $studentCode)) {
            AuditLog::record('support.act_as.denied', $user, metadata: [
                'student' => $studentCode,
                'role' => $user->effectiveRole()?->value,
            ]);

            abort(403, 'ไม่มีสิทธิ์ดูข้อมูลของนักศึกษาคนนี้');
        }

        $actAs = new ActAs($studentCode, $user->effectiveRole(), $user, $request->string('reason')->toString());

        AuditLog::record('support.act_as', $user, metadata: [
            'student' => $studentCode,
            'role' => $actAs->role->value,
            'reason' => $actAs->reason,
        ]);

        return $actAs;
    }

    /**
     * Requests from the admin test console are authenticated by session cookie
     * (Sanctum marks them); everything else comes with a Bearer token.
     */
    private function isConsole(SendChatMessageRequest $request): bool
    {
        return (bool) $request->attributes->get('sanctum');
    }
}
