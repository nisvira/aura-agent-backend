<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id (logs, audit rows, ai_requests and the
 * X-Request-Id response header) and writes the access log once the response
 * has been sent, so streamed answers are timed to their last byte.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();

        Context::add('request_id', $requestId);
        $request->attributes->set('started_at', hrtime(true));

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('up')) {
            return;
        }

        $user = $request->user();
        $status = $response->getStatusCode();

        Log::channel('access')->info('request', [
            'request_id' => Context::get('request_id'),
            'method' => $request->method(),
            // Path only: query strings can carry secrets (?code= on the OAuth callback).
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'status' => $status,
            'duration_ms' => intdiv(hrtime(true) - $request->attributes->get('started_at', hrtime(true)), 1_000_000),
            'user_id' => $user?->getKey(),
            'auth' => $user ? $this->authMethod($request) : null,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        if ($status === 403 || $status === 429) {
            AuditLog::record($status === 403 ? 'access.denied' : 'ratelimit.exceeded', $user, metadata: [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
            ]);
        }
    }

    private function authMethod(Request $request): string
    {
        return match (true) {
            in_array('openwebui.auth', $request->route()?->middleware() ?? [], true) => 'openwebui',
            (bool) $request->attributes->get('sanctum') || $request->hasSession() && ! $request->bearerToken() => 'session',
            default => 'token',
        };
    }
}
