<?php

namespace App\Providers;

use App\Http\Requests\ChatCompletionRequest;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Agent\AuraAgentClient;
use App\Services\Chat\ChatService;
use App\Services\Llm\OllamaClient;
use App\Services\YruPassport\YruPassportService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AuraAgentClient::class, fn () => new AuraAgentClient(config('services.aura_agent')));
        $this->app->singleton(OllamaClient::class, fn () => new OllamaClient(config('services.ollama')));
        $this->app->singleton(YruPassportService::class, fn () => new YruPassportService(config('services.yru_passport')));

        $this->app->singleton(ChatService::class, fn ($app) => new ChatService(
            $app->make(AuraAgentClient::class),
            $app->make(YruPassportService::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // JSON dates in Thai time with the offset, e.g. 2026-10-22T16:13:20+07:00
        // (Laravel's default is UTC with a Z suffix).
        Date::serializeUsing(fn ($date) => $date->toIso8601String());

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // Test console: admins, plus instructors (to ask about their advisees in their own name).
        Gate::define('console', fn (User $user) => in_array($user->effectiveRole(), [UserRole::Admin, UserRole::Instructor], true));

        // Asking about a student through act_as_student (AURA-AGENT support mode, still to come):
        // admins only. Instructors don't need it — they ask in their own name and AURA-AGENT
        // (3ed745d) looks up and enforces their advisee list itself.
        Gate::define('view-student', fn (User $user, string $studentCode) => match ($user->effectiveRole()) {
            UserRole::Admin => true,
            UserRole::Student => $studentCode === $user->username,
            default => false,
        });
    }

    private function configureRateLimiting(): void
    {
        // General API traffic.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Login endpoints: per IP, to slow down token guessing.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Endpoints that hit AURA-AGENT. Keys are prefixed so the two limits don't share a counter.
        RateLimiter::for('chat', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            // Open WebUI fires 2-4 background tasks (title, tags, follow-ups) per message;
            // give them their own bucket so they don't eat the user's chat quota.
            if ($request->routeIs('openai.chat')
                && ChatCompletionRequest::isTaskPayload($request->header('X-OpenWebUI-Task'), $request->input('messages'))) {
                return Limit::perMinute(30)->by('chat-task:'.$key);
            }

            return [
                Limit::perMinute(config('chat.rate_limit.per_minute'))->by('chat-min:'.$key)
                    ->response(fn (Request $request, array $headers) => response()->json(['message' => 'ส่งคำถามบ่อยเกินไป กรุณารอสักครู่'], 429, $headers)),
                Limit::perDay(config('chat.rate_limit.per_day'))->by('chat-day:'.$key)
                    ->response(fn (Request $request, array $headers) => response()->json(['message' => 'ใช้งานครบโควตาของวันนี้แล้ว'], 429, $headers)),
            ];
        });
    }
}
