<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiErrorException;
use App\Models\ApiCredential;
use App\Services\VaultService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVaultSession
{
    public function __construct(public VaultService $vault) {}

    public function handle(Request $request, Closure $next): Response
    {
        $credential = $request->attributes->get('api_credential');
        if (! $credential instanceof ApiCredential) {
            throw new ApiErrorException('unauthenticated', 'Autentikasi perangkat diperlukan.', 401);
        }

        $vaultToken = $request->header('X-Vault-Token');
        $isBackgroundPoll = $request->header('X-Background-Poll') === '1';

        $session = $this->vault->validateSession(
            $credential,
            is_string($vaultToken) ? $vaultToken : null,
            ! $isBackgroundPoll,
        );

        $request->attributes->set('vault_session', $session);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
