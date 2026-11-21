<?php

namespace Database\Factories;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Models\DriveFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DriveFile>
 */
class DriveFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = 'synthetic-content-'.Str::random(16);

        return [
            'user_id' => User::factory(),
            'folder_id' => null,
            'space' => Space::Regular,
            'name' => fake()->word().'.pdf',
            'object_key' => 'objects/'.Str::uuid()->toString(),
            'thumbnail_key' => null,
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'mime_type' => 'application/pdf',
            'category' => FileCategory::Document,
            'media_taken_at' => null,
            'status' => 'ready',
            'deleted_at' => null,
            'purge_after_at' => null,
        ];
    }
}
