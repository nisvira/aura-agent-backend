<?php

namespace App\Services\Llm;

use App\Exceptions\UpstreamServiceException;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Client for the Ollama chat API (https://github.com/ollama/ollama/blob/main/docs/api.md#generate-a-chat-completion).
 */
class OllamaClient
{
    public function __construct(private readonly array $config) {}

    public function model(): string
    {
        return $this->config['model'];
    }

    /**
     * Send the full message list and wait for the complete answer.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, stats: array<string, mixed>}
     */
    public function chat(array $messages): array
    {
        try {
            $response = $this->http()
                ->post('/api/chat', $this->payload($messages, stream: false))
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new UpstreamServiceException('ollama', 'ระบบ AI ไม่สามารถตอบได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง', previous: $e);
        }

        return [
            'content' => (string) $response->json('message.content', ''),
            'stats' => $this->stats($response->json()),
        ];
    }

    /**
     * Stream the answer token by token.
     *
     * Ollama returns newline-delimited JSON; each line carries a piece of
     * message.content and the last line has "done": true plus timing stats.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return Generator<int, string, mixed, array<string, mixed>> yields content deltas, returns stats
     */
    public function chatStream(array $messages): Generator
    {
        try {
            $response = $this->http()
                ->withOptions(['stream' => true])
                ->post('/api/chat', $this->payload($messages, stream: true))
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new UpstreamServiceException('ollama', 'ระบบ AI ไม่สามารถตอบได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง', previous: $e);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $chunk = json_decode($line, true);

                if (isset($chunk['error'])) {
                    throw new UpstreamServiceException('ollama', 'ระบบ AI เกิดข้อผิดพลาดระหว่างตอบคำถาม');
                }

                if ($delta = data_get($chunk, 'message.content')) {
                    yield $delta;
                }

                if ($chunk['done'] ?? false) {
                    return $this->stats($chunk);
                }
            }
        }

        return [];
    }

    private function payload(array $messages, bool $stream): array
    {
        return [
            'model' => $this->config['model'],
            'messages' => $messages,
            'stream' => $stream,
            'keep_alive' => $this->config['keep_alive'],
            'options' => [
                'temperature' => $this->config['temperature'],
                'num_ctx' => $this->config['num_ctx'],
            ],
        ];
    }

    private function stats(array $data): array
    {
        return [
            'model' => $data['model'] ?? $this->config['model'],
            'prompt_tokens' => $data['prompt_eval_count'] ?? null,
            'completion_tokens' => $data['eval_count'] ?? null,
            'duration_ms' => isset($data['total_duration']) ? intdiv($data['total_duration'], 1_000_000) : null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout'])
            ->connectTimeout(5);
    }
}
