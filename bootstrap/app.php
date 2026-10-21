<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateOpenWebUi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Outermost: request id for every log line, plus the access log after the response is sent.
        $middleware->prepend(AssignRequestId::class);

        $middleware->alias([
            'openwebui.auth' => AuthenticateOpenWebUi::class,
        ]);

        // Lets the test console call /api/* with its session cookie (Sanctum SPA auth).
        // Requests without a same-site Origin/Referer (Open WebUI, Bearer clients) are unaffected.
        $middleware->statefulApi();

        $middleware->redirectUsersTo(fn () => route('console'));

        // Must resolve the user before rate limiting, or every Open WebUI user would share one IP-based bucket.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateOpenWebUi::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // request_id is attached to every log line through Context (AssignRequestId).
        $exceptions->context(fn () => ['user_id' => rescue(fn () => auth()->id(), report: false)]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
