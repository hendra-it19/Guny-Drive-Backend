<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestMetrics
{
    public function __construct(public AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = Str::uuid()->toString();
        $request->attributes->set('request_id', $requestId);
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $response->headers->set('X-Request-Id', $requestId);

        $credential = $request->attributes->get('api_credential');
        $userId = $credential instanceof ApiCredential ? $credential->user_id : null;
        $credentialId = $credential instanceof ApiCredential ? $credential->id : null;

        $routeUri = $request->route()?->uri();
        $routeTemplate = is_string($routeUri) ? '/'.ltrim($routeUri, '/') : '/api/v1/unmatched';

        $errorCode = null;
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && isset($data['code']) && is_string($data['code'])) {
                $errorCode = $data['code'];
            }
        }

        try {
            $this->audit->recordApiRequest(
                userId: $userId,
                apiCredentialId: $credentialId,
                requestId: $requestId,
                method: $request->getMethod(),
                routeTemplate: $routeTemplate,
                statusCode: $response->getStatusCode(),
                errorCode: $errorCode,
                durationMs: $durationMs,
            );
        } catch (\Throwable) {
            // Do not fail API responses if metrics storage is momentarily unavailable.
        }

        return $response;
    }
}
