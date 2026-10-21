<?php

namespace App\Http\Controllers;

use App\Models\AiRequest;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

/**
 * Admin test console: chat against the real API, a health check of every
 * dependency (MySQL, Ollama, RAG) and the log viewer.
 */
class ConsoleController extends Controller
{
    /** Display timezone for the log pages; rows are stored in UTC. */
    private const TZ = 'Asia/Bangkok';

    public function index(): View
    {
        return view('console.index');
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'database' => $this->check(fn () => sprintf(
                'users %d · sessions %d · messages %d',
                User::count(), ChatSession::count(), ChatMessage::count(),
            )),
            'ollama' => $this->check(function () {
                $model = config('services.ollama.model');
                $http = $this->http(config('services.ollama.base_url'));

                $installed = collect($http->get('/api/tags')->throw()->json('models'))->pluck('name');
                $loaded = collect($http->get('/api/ps')->throw()->json('models'))->pluck('name');

                throw_unless($installed->contains($model), new \RuntimeException("model {$model} is not installed"));

                return $model.($loaded->contains($model) ? ' · loaded in memory' : ' · not loaded yet (first answer will be slow)');
            }),
            'rag' => $this->check(function () {
                $count = count($this->http(config('services.rag.base_url'))
                    ->post(config('services.rag.search_path'), ['query' => 'ทดสอบ', 'top_k' => 1])
                    ->throw()
                    ->json('results') ?? []);

                return config('services.rag.base_url')." · returned {$count} result(s)";
            }),
        ]);
    }

    public function logs(Request $request): View
    {
        $since = now()->subDays(13)->startOfDay();
        $questions = AiRequest::where('is_task', false);

        // Totals over the last 7 days; p95 is computed in PHP (one int column, cheap at this volume).
        $week = (clone $questions)->where('created_at', '>=', now()->subDays(7));
        $durations = (clone $week)->where('status', 'success')->orderBy('total_ms')->pluck('total_ms');
        $weekCount = (clone $week)->count();

        $summary = [
            'questions' => $weekCount,
            'users' => (clone $week)->distinct('user_id')->count('user_id'),
            'error_rate' => $weekCount ? (clone $week)->where('status', 'error')->count() / $weekCount : null,
            'no_hits' => (clone $week)->where('rag_status', 'ok')->where('rag_hits', 0)->count(),
            // Nearest-rank percentile: the smallest value with at least 95% of samples at or below it.
            'p95_ms' => $durations->isEmpty() ? null : $durations[(int) ceil(0.95 * $durations->count()) - 1],
            'tasks' => AiRequest::where('is_task', true)->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        $offset = now(self::TZ)->format('P');
        $daily = (clone $questions)
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(CONVERT_TZ(created_at, '+00:00', ?)) as day", [$offset])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'error') as errors")
            ->selectRaw("SUM(status = 'aborted') as aborted")
            ->selectRaw("SUM(rag_status = 'ok' AND rag_hits = 0) as no_hits")
            ->selectRaw("SUM(rag_status = 'error') as rag_down")
            ->selectRaw('AVG(ttft_ms) as avg_ttft_ms')
            ->selectRaw("AVG(CASE WHEN status = 'success' THEN total_ms END) as avg_total_ms")
            ->selectRaw('SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) as tokens')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        $knowledgeGaps = (clone $questions)
            ->with(['question:id,content', 'user:id,name'])
            ->where('rag_status', 'ok')
            ->where('rag_hits', 0)
            ->latest('id')
            ->limit(30)
            ->get();

        $failures = AiRequest::with('user:id,name')
            ->whereIn('status', ['error', 'aborted'])
            ->orWhere('rag_status', 'error')
            ->latest('id')
            ->limit(20)
            ->get();

        $event = $request->string('event')->toString();
        $audit = AuditLog::with('user:id,name,email')
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('console.logs', [
            'tz' => self::TZ,
            'summary' => $summary,
            'daily' => $daily,
            'knowledgeGaps' => $knowledgeGaps,
            'failures' => $failures,
            'audit' => $audit,
            'event' => $event,
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
        ]);
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function check(\Closure $probe): array
    {
        try {
            return ['ok' => true, 'detail' => $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => \Illuminate\Support\Str::limit($e->getMessage(), 160)];
        }
    }

    private function http(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)->acceptJson()->connectTimeout(2)->timeout(5);
    }
}
