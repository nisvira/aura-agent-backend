<?php

namespace App\Services\Chat;

use App\Services\Rag\RagChunk;
use Illuminate\Support\Str;

/**
 * Builds the Ollama "messages" array:
 *   [system: instructions + numbered RAG context] + [previous turns] + [current question]
 */
class PromptBuilder
{
    public function __construct(
        private readonly string $systemPrompt,
        private readonly int $maxContextChars,
    ) {}

    /**
     * @param  list<RagChunk>  $chunks
     * @param  list<array{role: string, content: string}>  $history  oldest first
     * @return list<array{role: string, content: string}>
     */
    public function build(string $question, array $chunks, array $history): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt."\n\n".$this->formatContext($chunks)],
        ];

        foreach ($history as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * @param  list<RagChunk>  $chunks
     */
    private function formatContext(array $chunks): string
    {
        if ($chunks === []) {
            return "ข้อมูลอ้างอิง:\n(ไม่พบข้อมูลที่เกี่ยวข้องในฐานความรู้)";
        }

        $budget = $this->maxContextChars;
        $blocks = [];

        foreach ($chunks as $i => $chunk) {
            if ($budget <= 0) {
                break;
            }

            $content = Str::limit(trim($chunk->content), $budget, '…');
            $budget -= mb_strlen($content);

            $header = '['.($i + 1).']'.($chunk->source ? " แหล่งที่มา: {$chunk->source}" : '');
            $blocks[] = $header."\n".$content;
        }

        return "ข้อมูลอ้างอิง:\n".implode("\n\n---\n\n", $blocks);
    }
}
