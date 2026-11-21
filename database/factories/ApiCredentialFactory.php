<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\ApiCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiCredential>
 */
class ApiCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = 'gd_dev_'.Str::random(40);

        return [
            'user_id' => User::factory(),
            'client_type' => ClientType::Device,
            'label' => 'Perangkat '.fake()->word(),
            'device_id' => null,
            'integration_endpoint_id' => null,
            'scopes' => ['*'],
            'token_prefix' => substr($plain, 0, 14),
            'token_hash' => hash('sha256', $plain),
            'expires_at' => null,
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
}
