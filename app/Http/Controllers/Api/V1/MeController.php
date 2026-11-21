<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\User;
use App\Models\VaultConfig;
use App\Services\PublicStorageLink;
use App\Services\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(public StorageQuotaService $quota) {}

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');
        $usage = $this->quota->usageSummary($user);
        $vaultConfigured = VaultConfig::query()->where('user_id', $user->id)->exists();

        return response()->json([
            'server' => [
                'name' => (string) config('gunydrive.server_name', 'GunyDrive'),
                'api_version' => 'v1',
                'storage_link_ready' => app(PublicStorageLink::class)->ready(),
                'time_utc' => now()->toIso8601String(),
            ],
            'owner' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'avatar_url' => $user->avatarUrl(),
                'passkey_enabled' => $user->biometric_token_hash !== null,
            ],
            'device' => $credential->device !== null ? [
                'id' => $credential->device->id,
                'name' => $credential->device->name,
                'platform' => $credential->device->platform,
                'last_backup_at' => $credential->device->last_backup_at?->toIso8601String(),
            ] : null,
            'credential' => [
                'id' => $credential->id,
                'label' => $credential->label,
                'client_type' => $credential->client_type->value,
                'expires_at' => $credential->expires_at?->toIso8601String(),
            ],
            'vault_configured' => $vaultConfigured,
            'usage' => $usage,
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->quota->usageSummary($user));
    }
}
