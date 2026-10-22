<?php

namespace App\Services\Agent;

use App\Exceptions\UpstreamServiceException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client for AURA-AGENT (/opt/aura-agent, docs/01-api-contract.md): a LangGraph
 * agent that answers students' activity questions from the Activity API.
 *
 * Every call carries the service key plus the student's own YRU Passport
 * access token; the agent asks Passport who the student is.
 */
class AuraAgentClient
{
    public const UNAVAILABLE = 'ผู้ช่วยไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่อีกครั้ง';

    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return filled($this->config['base_url']) && filled($this->config['service_key']);
    }

    /**
     * Asking on behalf of a student needs the agent's support mode (its own key).
     */
    public function supportEnabled(): bool
    {
        return $this->enabled() && filled($this->config['support_key'] ?? null);
    }

    /**
     * POST /v1/chat. Yields the agent's SSE events as they arrive:
     * status {text} · token {text} · done {thread_id, tools_used, latency_ms} · error {code, text}.
     * "error" arrives with HTTP 200 inside the stream, in place of "done".
     *
     * Normally carries the student's own Passport token. With $actAs it uses the
     * support mode instead (X-Support-Key + X-Act-As-* headers, no token).
     *
     * @return Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function chat(?string $passportToken, string $threadId, string $message, ?ActAs $actAs = null): Generator
    {
        try {
            $response = ($actAs ? $this->supportHttp($actAs) : $this->http((string) $passportToken))
                ->withOptions(['stream' => true])
                ->accept('text/event-stream')
                ->post('/v1/chat', ['thread_id' => $threadId, 'message' => $message])
                ->throw();
        } catch (ConnectionException $e) {
            throw new UpstreamServiceException('aura_agent', self::UNAVAILABLE, 503, $e, 'AGENT_UNAVAILABLE');
        } catch (RequestException $e) {
            // 401 = wrong/missing service key (our config), 422 = body rejected.
            Log::error('AURA-AGENT rejected the request', ['status' => $e->response->status(), 'body' => $e->response->body()]);

            throw new UpstreamServiceException('aura_agent', self::UNAVAILABLE, 503, $e, 'AGENT_REJECTED');
        }

        $body = $response->toPsrResponse()->getBody();
        $raw = '';
        $buffer = '';

        while (! $body->eof()) {
            // sse-starlette ends lines with \r\n; normalise, but hold a trailing \r
            // back in case its \n arrives in the next chunk.
            $raw .= $body->read(2048);
            $hold = str_ends_with($raw, "\r") ? "\r" : '';
            $buffer .= str_replace(["\r\n", "\r"], "\n", $hold === '' ? $raw : substr($raw, 0, -1));
            $raw = $hold;

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                if (($event = $this->parseBlock($block)) !== null) {
                    yield $event;
                }
            }
        }
    }

    /**
     * DELETE /v1/chat/{thread_id}: forget the agent's memory of a conversation.
     * Best effort — a failure is logged, never thrown.
     */
    public function deleteThread(string $passportToken, string $threadId): bool
    {
        try {
            return $this->http($passportToken)->delete('/v1/chat/'.rawurlencode($threadId))->successful();
        } catch (Throwable $e) {
            Log::warning('AURA-AGENT thread delete failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{status?: string, ollama?: string, activity_api?: string}
     */
    public function health(): array
    {
        return Http::baseUrl((string) $this->config['base_url'])
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout(5)
            ->get('/v1/health')
            ->throw()
            ->json();
    }

    /**
     * @return array{event: string, data: array<string, mixed>}|null
     */
    private function parseBlock(string $block): ?array
    {
        $event = 'message';
        $data = [];

        foreach (explode("\n", $block) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue; // comment / keep-alive ping
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            match ($field) {
                'event' => $event = $value,
                'data' => $data[] = $value,
                default => null,
            };
        }

        if ($data === []) {
            return null;
        }

        return ['event' => $event, 'data' => json_decode(implode("\n", $data), true) ?? []];
    }

    private function supportHttp(ActAs $actAs): PendingRequest
    {
        return Http::baseUrl((string) $this->config['base_url'])
            ->withHeaders([
                'X-Service-Key' => (string) $this->config['service_key'],
                'X-Support-Key' => (string) $this->config['support_key'],
                'X-Act-As-Student' => $actAs->studentCode,
                'X-Act-As-Role' => $actAs->role->value,
                'X-Act-As-Actor' => $actAs->actorId(),
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($this->config['timeout']);
    }

    private function http(string $passportToken): PendingRequest
    {
        return Http::baseUrl((string) $this->config['base_url'])
            ->withToken($passportToken)
            ->withHeader('X-Service-Key', (string) $this->config['service_key'])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout($this->config['timeout']);
    }
}
