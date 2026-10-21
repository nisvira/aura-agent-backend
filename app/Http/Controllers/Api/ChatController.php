<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Concerns\LocksChatPerUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\StreamedEvent;
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
        $lock = $this->acquireUserLock($request->user()->id);

        try {
            $result = $this->chat->reply($request->user(), $request->string('message')->toString(), $request->sessionId());
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
     * Server-Sent Events: "sources", then many "delta", then "done".
     */
    public function stream(SendChatMessageRequest $request): StreamedResponse
    {
        $user = $request->user();
        $question = $request->string('message')->toString();
        $sessionId = $request->sessionId();

        // Check ownership now: once the stream starts, headers are sent and a 404 is no longer possible.
        if ($sessionId !== null) {
            $user->chatSessions()->findOrFail($sessionId);
        }

        $lock = $this->acquireUserLock($user->id);

        return response()->eventStream(function () use ($lock, $user, $question, $sessionId) {
            // Keep running after a client disconnect so the loop can stop cleanly and release the lock.
            ignore_user_abort(true);

            try {
                foreach ($this->chat->stream($user, $question, $sessionId) as $event) {
                    yield new StreamedEvent(event: $event['type'], data: $event);
                }
            } catch (UpstreamServiceException $e) {
                yield new StreamedEvent(event: 'error', data: ['message' => $e->getMessage()]);
            } finally {
                $lock->release();
            }
        }, headers: ['X-Accel-Buffering' => 'no']);
    }
}
