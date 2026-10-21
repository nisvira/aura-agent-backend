<?php

namespace App\Services\Rag;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Python RAG service (vector DB search).
 *
 * Expected contract:
 *   POST {RAG_API_URL}/api/rag/search  {"query": "...", "top_k": 5}
 *   200 {"results": [{"content": "...", "source": "...", "score": 0.82, "metadata": {...}}]}
 */
class RagClient
{
    public function __construct(private readonly array $config) {}

    /**
     * Retrieve context chunks relevant to the query.
     *
     * RAG is treated as optional: if the service is down the chat still answers
     * (without context) instead of failing the whole request.
     *
     * @return list<RagChunk>
     */
    public function search(string $query, ?int $topK = null): array
    {
        try {
            $response = $this->http()
                ->post($this->config['search_path'], [
                    'query' => $query,
                    'top_k' => $topK ?? $this->config['top_k'],
                ])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            Log::warning('RAG search failed, continuing without context', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $items = $response->json('results') ?? $response->json('data') ?? [];

        return collect($items)
            ->map(fn (array $item) => new RagChunk(
                content: (string) ($item['content'] ?? $item['text'] ?? $item['page_content'] ?? ''),
                source: $item['source'] ?? data_get($item, 'metadata.source'),
                score: isset($item['score']) ? (float) $item['score'] : null,
                metadata: $item['metadata'] ?? [],
            ))
            ->filter(fn (RagChunk $chunk) => $chunk->content !== ''
                && ($chunk->score === null || $chunk->score >= $this->config['min_score']))
            ->values()
            ->all();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout'])
            ->connectTimeout(3)
            ->when($this->config['api_key'], fn (PendingRequest $http, string $key) => $http->withHeader('X-API-Key', $key));
    }
}
