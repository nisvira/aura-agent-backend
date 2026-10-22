<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thrown when an external dependency (Ollama, YRU Passport) fails or times out.
 */
class UpstreamServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        string $message,
        public readonly int $status = 503,
        ?\Throwable $previous = null,
        /** Machine-readable reason, e.g. AURA-AGENT's TOKEN_EXPIRED or NOT_STUDENT. */
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Refusals (401/403, e.g. NOT_STUDENT, PASSPORT_REQUIRED) are expected and
     * already in the AI/audit logs, so they are not reported. Real upstream
     * failures get one warning line instead of a full stack trace.
     */
    public function report(): bool
    {
        if ($this->status >= 500) {
            Log::warning('Upstream service failed', [
                'service' => $this->service,
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'cause' => $this->getPrevious()?->getMessage(),
            ]);
        }

        return true;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'service' => $this->service,
            'code' => $this->errorCode,
        ]), $this->status);
    }
}
