<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Exceptions\ApiErrorException;
use App\Models\ApiCredential;
use App\Models\Device;
use App\Models\IntegrationEndpoint;
use App\Models\User;
use App\Models\VaultSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiCredentialService
{
    public function __construct(public AuditService $audit) {}

    /**
     * @return array{credential: ApiCredential, device: Device, plain_token: string}
     */
    public function issueDeviceToken(
        User $user,
        string $deviceName,
        ?string $platform = null,
        ?CarbonInterface $expiresAt = null,
        ?string $requestId = null,
    ): array {
        $plainToken = 'gd_dev_'.Str::random(48);

        /** @var array{credential: ApiCredential, device: Device, plain_token: string} $result */
        $result = DB::transaction(function () use ($user, $deviceName, $platform, $expiresAt, $plainToken): array {
            $device = Device::query()->create([
                'user_id' => $user->id,
                'name' => $deviceName,
                'platform' => $platform,
                'last_seen_at' => null,
            ]);

            $credential = ApiCredential::query()->create([
                'user_id' => $user->id,
                'client_type' => ClientType::Device,
                'label' => $deviceName,
                'device_id' => $device->id,
                'integration_endpoint_id' => null,
                'scopes' => ['*'],
                'token_prefix' => substr($plainToken, 0, 14),
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => $expiresAt,
            ]);

            return [
                'credential' => $credential,
                'device' => $device,
                'plain_token' => $plainToken,
            ];
        });

        $this->audit->recordEvent($user->id, 'credential.device_issued', 'success', [
            'credential_id' => $result['credential']->id,
            'device_id' => $result['device']->id,
        ], $requestId);

        return $result;
    }

    /**
     * @param  list<string>  $scopes
     * @return array{credential: ApiCredential, plain_token: string}
     */
    public function issueIntegrationToken(
        User $user,
        IntegrationEndpoint $endpoint,
        string $label,
        array $scopes,
        ?CarbonInterface $expiresAt = null,
        ?string $requestId = null,
    ): array {
        $plainToken = 'gd_int_'.Str::random(48);

        $credential = ApiCredential::query()->create([
            'user_id' => $user->id,
            'client_type' => ClientType::Integration,
            'label' => $label,
            'device_id' => null,
            'integration_endpoint_id' => $endpoint->id,
            'scopes' => $scopes,
            'token_prefix' => substr($plainToken, 0, 14),
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
        ]);

        $this->audit->recordEvent($user->id, 'credential.integration_issued', 'success', [
            'credential_id' => $credential->id,
            'endpoint_id' => $endpoint->id,
            'scopes' => $scopes,
        ], $requestId);

        return [
            'credential' => $credential,
            'plain_token' => $plainToken,
        ];
    }

    public function revoke(ApiCredential $credential, ?string $requestId = null): void
    {
        DB::transaction(function () use ($credential): void {
            $credential->revoked_at = now();
            $credential->save();

            VaultSession::query()
                ->where('api_credential_id', $credential->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if ($credential->device_id !== null) {
                $otherActive = ApiCredential::query()
                    ->where('device_id', $credential->device_id)
                    ->whereNull('revoked_at')
                    ->exists();

                if (! $otherActive) {
                    Device::query()
                        ->whereKey($credential->device_id)
                        ->update(['revoked_at' => now()]);
                }
            }
        });

        $this->audit->recordEvent($credential->user_id, 'credential.revoked', 'success', [
            'credential_id' => $credential->id,
            'client_type' => $credential->client_type->value,
        ], $requestId);
    }

    public function authenticate(string $plainToken): ApiCredential
    {
        $hash = hash('sha256', $plainToken);

        $credential = ApiCredential::query()
            ->with(['user', 'device', 'integrationEndpoint'])
            ->where('token_hash', $hash)
            ->first();

        if ($credential === null) {
            throw new ApiErrorException('token_invalid', 'Kredensial API tidak dikenali.', 401);
        }

        if ($credential->isRevoked()) {
            throw new ApiErrorException('token_revoked', 'Kredensial telah dicabut. Hubungkan ulang perangkat Anda.', 401);
        }

        if ($credential->isExpired()) {
            throw new ApiErrorException('token_expired', 'Kredensial sudah kedaluwarsa.', 401);
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        if ($credential->device !== null) {
            $credential->device->forceFill(['last_seen_at' => now()])->save();
        }

        return $credential;
    }
}
