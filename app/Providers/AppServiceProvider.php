<?php

namespace App\Providers;

use App\Http\Requests\ChatCompletionRequest;
use App\Services\Chat\ChatService;
use App\Services\Chat\PromptBuilder;
use App\Services\Llm\OllamaClient;
use App\Services\Rag\RagClient;
use App\Services\YruPassport\YruPassportService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RagClient::class, fn () => new RagClient(config('services.rag')));
        $this->app->singleton(OllamaClient::class, fn () => new OllamaClient(config('services.ollama')));
        $this->app->singleton(YruPassportService::class, fn () => new YruPassportService(config('services.yru_passport')));

        $this->app->singleton(PromptBuilder::class, fn () => new PromptBuilder(
            config('chat.system_prompt'),
            config('chat.max_context_chars'),
        ));

        $this->app->singleton(ChatService::class, fn ($app) => new ChatService(
            $app->make(RagClient::class),
            $app->make(OllamaClient::class),
            $app->make(PromptBuilder::class),
            config('chat.history_limit'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // General API traffic.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Login endpoints: per IP, to slow down token guessing.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Endpoints that hit RAG + Ollama. Keys are prefixed so the two limits don't share a counter.
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
                    ->response(fn () => response()->json(['message' => 'ส่งคำถามบ่อยเกินไป กรุณารอสักครู่'], 429)),
                Limit::perDay(config('chat.rate_limit.per_day'))->by('chat-day:'.$key)
                    ->response(fn () => response()->json(['message' => 'ใช้งานครบโควตาของวันนี้แล้ว'], 429)),
            ];
        });
    }
}
