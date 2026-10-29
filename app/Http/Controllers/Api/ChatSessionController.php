<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatSessionResource;
use App\Models\AuditLog;
use App\Services\Agent\AuraAgentClient;
use App\Services\YruPassport\YruPassportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;


class ChatSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ChatSessionResource::collection(
            $request->user()->chatSessions()->latest('updated_at')->paginate(20)
        );
    }

    public function show(Request $request, int $session): ChatSessionResource
    {
        return new ChatSessionResource(
            $request->user()->chatSessions()->with(['messages' => fn ($q) => $q->orderBy('id')])->findOrFail($session)
        );
    }

    public function update(Request $request, int $session): ChatSessionResource
    {
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $chatSession = $request->user()->chatSessions()->findOrFail($session);
        $chatSession->update($validated);

        return new ChatSessionResource($chatSession);
    }

    public function destroy(Request $request, int $session, AuraAgentClient $agent, YruPassportService $passport): Response
    {
        $chatSession = $request->user()->chatSessions()->findOrFail($session);
        $messageCount = $chatSession->messages()->count();
        $chatSession->delete();

        // Also forget the agent's own memory of this conversation (best effort).
        if ($chatSession->agent_thread_id && $agent->enabled() && ($token = $passport->accessTokenFor($request->user()))) {
            $agent->deleteThread($token, $chatSession->agent_thread_id);
        }

        AuditLog::record('chat.session.deleted', $request->user(), $chatSession, ['messages' => $messageCount]);

        return response()->noContent();
    }
}
