<?php

namespace App\Http\Controllers;

use App\Models\AiRequest;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Agent\AuraAgentClient;
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
            'agent' => $this->check(function () {
                $agent = app(AuraAgentClient::class);
                $base = config('services.aura_agent.base_url');

                throw_unless($base, new \RuntimeException('AURA_AGENT_URL is not set'));

                $health = $agent->health();
                $detail = "{$base} · ollama {$health['ollama']} · activity_api {$health['activity_api']}";

                throw_unless(($health['status'] ?? null) === 'ok', new \RuntimeException($detail));
                throw_unless($agent->enabled(), new \RuntimeException($detail.' · AURA_AGENT_SERVICE_KEY is not set (chat is unavailable)'));

                return $detail;
            }),
            // Only Open WebUI's background jobs (chat titles, tags) use Ollama directly.
            'ollama' => $this->check(function () {
                $model = config('services.ollama.model');
                $http = $this->http(config('services.ollama.base_url'));

                $installed = collect($http->get('/api/tags')->throw()->json('models'))->pluck('name');

                throw_unless($installed->contains($model), new \RuntimeException("model {$model} is not installed"));

                return $model.' · Open WebUI titles/tags only';
            }),
        ]);
    }

    public function logs(Request $request): View
    {
        $since = now()->subDays(13)->startOfDay();
        $questions = AiRequest::where('is_task', false);
        // Questions AURA turned away before asking the agent (no Passport login / not a student).
        $turnedAway = fn ($q) => $q->where(fn ($q) => $q->where('error', 'like', 'PASSPORT_REQUIRED:%')->orWhere('error', 'like', 'NOT_STUDENT:%'));

        // Totals over the last 7 days; p95 is computed in PHP (one int column, cheap at this volume).
        $week = (clone $questions)->where('created_at', '>=', now()->subDays(7));
        $durations = (clone $week)->where('status', 'success')->orderBy('total_ms')->pluck('total_ms');
        $weekCount = (clone $week)->count();

        $summary = [
            'questions' => $weekCount,
            'users' => (clone $week)->distinct('user_id')->count('user_id'),
            'error_rate' => $weekCount ? (clone $week)->where('status', 'error')->count() / $weekCount : null,
            'turned_away' => $turnedAway(clone $week)->count(),
            // Nearest-rank percentile: the smallest value with at least 95% of samples at or below it.
            'p95_ms' => $durations->isEmpty() ? null : $durations[(int) ceil(0.95 * $durations->count()) - 1],
            'tasks' => AiRequest::where('is_task', true)->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        // The DB session runs in Thai time (config/database.php), so DATE() is already local.
        $daily = (clone $questions)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'success') as answered")
            ->selectRaw("SUM(status = 'error') as errors")
            ->selectRaw("SUM(status = 'aborted') as aborted")
            ->selectRaw("SUM(error LIKE 'PASSPORT_REQUIRED:%' OR error LIKE 'NOT_STUDENT:%') as turned_away")
            ->selectRaw('AVG(ttft_ms) as avg_ttft_ms')
            ->selectRaw("AVG(CASE WHEN status = 'success' THEN total_ms END) as avg_total_ms")
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        // Which agent tools answered questions this week (tools_used is a JSON list per row).
        $tools = (clone $week)->where('status', 'success')->whereNotNull('tools_used')->pluck('tools_used')
            ->flatten()
            ->countBy()
            ->sortDesc();

        $errorCodes = (clone $week)->where('status', 'error')
            ->selectRaw("SUBSTRING_INDEX(error, ':', 1) as code, COUNT(*) as total")
            ->groupBy('code')
            ->orderByDesc('total')
            ->get();

        $failures = AiRequest::with('user:id,name')
            ->whereIn('status', ['error', 'aborted'])
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
            'tools' => $tools,
            'errorCodes' => $errorCodes,
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
