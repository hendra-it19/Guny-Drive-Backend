<?php

namespace App\Http\Middleware;

use App\Enums\ClientType;
use App\Exceptions\ApiErrorException;
use App\Services\ApiCredentialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiCredential
{
    public function __construct(public ApiCredentialService $credentials) {}

    public function handle(Request $request, Closure $next, string $expectedType = 'device'): Response
    {
        $plainToken = $request->bearerToken();
        if ($plainToken === null || trim($plainToken) === '') {
            throw new ApiErrorException('unauthenticated', 'Header otorisasi Bearer diperlukan.', 401);
        }

        $credential = $this->credentials->authenticate($plainToken);

        $required = ClientType::from($expectedType);
        if ($credential->client_type !== $required) {
            throw new ApiErrorException(
                'client_type_forbidden',
                $required === ClientType::Device
                    ? 'Token integrasi tidak diizinkan mengakses endpoint perangkat.'
                    : 'Gunakan kredensial integrasi khusus untuk endpoint ini.',
                403,
            );
        }

        if (! $credential->user->hasVerifiedEmail() && ! $request->routeIs('api.v1.logout')) {
            throw new ApiErrorException('email_verification_required', 'Verifikasi alamat email Anda untuk mengakses API.', 403);
        }

        $request->attributes->set('api_credential', $credential);
        $request->setUserResolver(static fn () => $credential->user);

        return $next($request);
    }
}
