<?php

namespace Database\Factories;

use App\Enums\Space;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'space' => Space::Regular,
            'name' => fake()->unique()->word(),
            'deleted_at' => null,
        ];
    }
}
