<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VaultConfig;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<VaultConfig>
 */
class VaultConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'passphrase_hash' => Hash::make('rahasia-privat'),
            'failed_attempts' => 0,
            'locked_until' => null,
            'passphrase_updated_at' => now(),
        ];
    }
}
