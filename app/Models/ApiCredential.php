<?php

namespace App\Models;

use App\Enums\ClientType;
use Carbon\CarbonInterface;
use Database\Factories\ApiCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property ClientType $client_type
 * @property string $label
 * @property string|null $device_id
 * @property string|null $integration_endpoint_id
 * @property list<string> $scopes
 * @property string $token_prefix
 * @property string $token_hash
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Device|null $device
 * @property-read IntegrationEndpoint|null $integrationEndpoint
 */
class ApiCredential extends Model
{
    /** @use HasFactory<ApiCredentialFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'client_type',
        'label',
        'device_id',
        'integration_endpoint_id',
        'scopes',
        'token_prefix',
        'token_hash',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<IntegrationEndpoint, $this>
     */
    public function integrationEndpoint(): BelongsTo
    {
        return $this->belongsTo(IntegrationEndpoint::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
