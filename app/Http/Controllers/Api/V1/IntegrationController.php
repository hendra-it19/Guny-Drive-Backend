<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\IntegrationEndpoint;
use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function __construct(public IntegrationService $integrations) {}

    public function invoke(Request $request, string $slug): JsonResponse
    {
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');

        $endpoint = IntegrationEndpoint::query()
            ->with('user')
            ->where('slug', strtolower($slug))
            ->first();

        if ($endpoint === null) {
            throw new ApiErrorException('endpoint_not_found', 'Endpoint integrasi tidak ditemukan.', 404);
        }

        $cursor = $request->query('cursor');
        $perPage = (int) $request->query('per_page', '40');

        $result = $this->integrations->execute(
            $endpoint,
            $credential,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            $perPage,
        );

        return response()->json($result);
    }
}
