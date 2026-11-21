<?php

namespace App\Services;

use App\Models\ApiRequestLog;
use App\Models\AuditEvent;

class AuditService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordEvent(
        ?int $userId,
        string $action,
        string $outcome,
        array $meta = [],
        ?string $requestId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'user_id' => $userId,
            'request_id' => $requestId,
            'action' => $action,
            'outcome' => $outcome,
            'meta' => $this->sanitizeMeta($meta),
            'created_at' => now(),
        ]);
    }

    public function recordApiRequest(
        ?int $userId,
        ?string $apiCredentialId,
        string $requestId,
        string $method,
        string $routeTemplate,
        int $statusCode,
        ?string $errorCode,
        int $durationMs,
    ): ApiRequestLog {
        return ApiRequestLog::query()->create([
            'user_id' => $userId,
            'api_credential_id' => $apiCredentialId,
            'request_id' => $requestId,
            'method' => strtoupper($method),
            'route_template' => $routeTemplate,
            'status_code' => $statusCode,
            'error_code' => $errorCode,
            'duration_ms' => max(0, $durationMs),
            'created_at' => now(),
        ]);
    }

    /**
     * Strip any potentially sensitive keys before persisting audit metadata.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitizeMeta(array $meta): array
    {
        $forbidden = ['token', 'passphrase', 'password', 'authorization', 'secret', 'filename', 'query'];
        $clean = [];

        foreach ($meta as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $forbidden, true)) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
