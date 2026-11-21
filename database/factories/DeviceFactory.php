<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Ponsel '.fake()->firstName(),
            'platform' => fake()->randomElement(['android', 'ios']),
            'last_seen_at' => now(),
            'last_backup_at' => null,
            'revoked_at' => null,
        ];
    }
}
