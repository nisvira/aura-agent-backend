<?php

namespace App\Services\Rag;

final readonly class RagChunk
{
    public function __construct(
        public string $content,
        public ?string $source = null,
        public ?float $score = null,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'score' => $this->score,
            'metadata' => $this->metadata,
        ];
    }
}
