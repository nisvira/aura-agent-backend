<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Concerns\LocksChatPerUser;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateOpenWebUi;
use App\Http\Requests\ChatCompletionRequest;
use App\Services\Chat\AiTrace;
use App\Services\Chat\ChatService;
use App\Services\Llm\OllamaClient;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * OpenAI-compatible API so Open WebUI can use Aura as a model.
 * In Open WebUI: Admin → Settings → Connections → OpenAI API, URL http://host.docker.internal:8000/api/v1
 */
class OpenAiController extends Controller
{
    use LocksChatPerUser;

    public function __construct(
        private readonly ChatService $chat,
        private readonly OllamaClient $llm,
    ) {}

    /**
     * GET /api/v1/models
     */
    public function models(): JsonResponse
    {
        return response()->json([
            'object' => 'list',
            'data' => [[
                'id' => config('openwebui.model_id'),
                'object' => 'model',
                'created' => 1760918400,
                'owned_by' => 'yru',
            ]],
        ]);
    }

    /**
     * POST /api/v1/chat/completions
     */
    public function chatCompletions(ChatCompletionRequest $request): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $stream = $request->boolean('stream');

        // Title/tag/follow-up generation: plain Ollama call, no chat history saved.
        if ($request->isTask()) {
            $messages = $request->conversation();
            $trace = AiTrace::start('openwebui', $user->id, $request->question(), isTask: true);

            return $stream
                ? $this->streamResponse($this->taskEvents($messages, $trace))
                : $this->guard(fn () => $this->completion(...$this->taskAnswer($messages, $trace)));
        }

        // X-OpenWebUI-Chat-Id is forwarded together with the user headers. Without it,
        // fall back to grouping by the conversation's first question.
        $externalId = $request->header('X-OpenWebUI-Chat-Id') ?: 'hash:'.sha1($request->firstQuestion());
        $session = $this->chat->sessionForExternalChat($user, $externalId, $request->firstQuestion());
        $question = $request->question();

        $lock = $this->acquireUserLock($user->id);

        if ($stream) {
            return $this->streamResponse($this->chat->streamInSession($session, $question), $lock->release(...));
        }

        try {
            return $this->guard(function () use ($session, $question) {
                $message = $this->chat->replyInSession($session, $question)['message'];

                return $this->completion($message->content, $message->metadata);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function taskAnswer(array $messages, AiTrace $trace): array
    {
        $trace->llmStarted($this->llm->model());

        try {
            $answer = $this->llm->chat($messages);
        } catch (Throwable $e) {
            $trace->finish('error', error: $e->getMessage());

            throw $e;
        }

        $trace->llmFinished($answer['stats']);
        $trace->finish('success');

        return [$answer['content'], $answer['stats']];
    }

    /**
     * Wrap raw Ollama deltas in the same event shape ChatService::streamInSession() yields.
     */
    private function taskEvents(array $messages, AiTrace $trace): Generator
    {
        $status = 'aborted';
        $error = null;

        try {
            $trace->llmStarted($this->llm->model());
            $tokens = $this->llm->chatStream($messages);

            foreach ($tokens as $delta) {
                $trace->firstToken();
                yield ['type' => 'delta', 'content' => $delta];
            }

            $trace->llmFinished($stats = $tokens->getReturn() ?? []);
            $status = 'success';

            yield ['type' => 'done', 'usage' => $stats];
        } catch (Throwable $e) {
            $status = 'error';
            $error = $e->getMessage();

            throw $e;
        } finally {
            $trace->finish($status, error: $error);
        }
    }

    private function completion(string $content, array $stats): JsonResponse
    {
        return response()->json([
            'id' => 'chatcmpl-'.Str::random(24),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => config('openwebui.model_id'),
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => $this->usage($stats),
        ]);
    }

    /**
     * Emit events as OpenAI "chat.completion.chunk" SSE lines, ending with "data: [DONE]".
     * (Laravel's eventStream() adds "event:" lines and a "</stream>" terminator, which OpenAI clients don't expect.)
     */
    private function streamResponse(Generator $events, ?\Closure $onFinish = null): StreamedResponse
    {
        return response()->stream(function () use ($events, $onFinish) {
            ignore_user_abort(true);

            $id = 'chatcmpl-'.Str::random(24);
            $created = time();

            $send = function (array $payload) {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            $chunk = fn (array $delta, ?string $finish = null, ?array $usage = null) => array_filter([
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => config('openwebui.model_id'),
                'choices' => [['index' => 0, 'delta' => $delta ?: new \stdClass, 'finish_reason' => $finish]],
                'usage' => $usage,
            ], fn ($v) => $v !== null);

            try {
                $send($chunk(['role' => 'assistant', 'content' => '']));

                foreach ($events as $event) {
                    if (connection_aborted()) {
                        return;
                    }

                    match ($event['type']) {
                        'delta' => $send($chunk(['content' => $event['content']])),
                        'done' => $send($chunk([], 'stop', $this->usage($event['usage'] ?? []))),
                        default => null,
                    };
                }
            } catch (UpstreamServiceException $e) {
                $send(['error' => ['message' => $e->getMessage(), 'type' => 'upstream_error', 'code' => $e->errorCode ?? $e->service]]);
            } finally {
                if ($onFinish) {
                    $onFinish();
                }
            }

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Render Ollama/RAG outages in the OpenAI error format so Open WebUI shows the message.
     */
    private function guard(\Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (UpstreamServiceException $e) {
            return AuthenticateOpenWebUi::error($e->getMessage(), $e->errorCode ?? $e->service, $e->status);
        }
    }

    private function usage(array $stats): array
    {
        $prompt = (int) ($stats['prompt_tokens'] ?? 0);
        $completion = (int) ($stats['completion_tokens'] ?? 0);

        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion];
    }

}
