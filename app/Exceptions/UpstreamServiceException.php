<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
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
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'service' => $this->service,
        ], $this->status);
    }
}
