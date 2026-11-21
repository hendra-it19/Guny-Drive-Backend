<?php

namespace Database\Factories;

use App\Models\StoragePolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoragePolicy>
 */
class StoragePolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quota_bytes' => (int) config('gunydrive.default_quota_bytes'),
            'max_file_bytes' => (int) config('gunydrive.default_max_file_bytes'),
            'physical_reserve_bytes' => (int) config('gunydrive.physical_reserve_bytes'),
            'trash_retention_days' => (int) config('gunydrive.trash_retention_days'),
            'used_bytes' => 0,
            'reserved_bytes' => 0,
        ];
    }
}
