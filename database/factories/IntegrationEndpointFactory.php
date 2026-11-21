<?php

namespace Database\Factories;

use App\Enums\IntegrationTemplate;
use App\Models\IntegrationEndpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationEndpoint>
 */
class IntegrationEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'slug' => fake()->unique()->slug(2),
            'name' => 'Integrasi '.fake()->word(),
            'template' => IntegrationTemplate::QuotaSummary,
            'folder_id' => null,
            'is_active' => true,
            'published_at' => now(),
            'disabled_at' => null,
        ];
    }
}
