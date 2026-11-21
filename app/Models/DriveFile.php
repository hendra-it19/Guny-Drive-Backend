<?php

namespace App\Models;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\ThumbnailPreset;
use Carbon\CarbonInterface;
use Database\Factories\DriveFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $folder_id
 * @property Space $space
 * @property string $name
 * @property string $object_key
 * @property string|null $thumbnail_key
 * @property string|null $thumbnail_preset
 * @property string|null $thumbnail_mime
 * @property int|null $thumbnail_size_bytes
 * @property string $thumbnail_status
 * @property int $size_bytes
 * @property string $sha256
 * @property string $mime_type
 * @property FileCategory $category
 * @property CarbonInterface|null $media_taken_at
 * @property string|null $source_device_id
 * @property string|null $source_asset_id
 * @property string|null $source_revision
 * @property string $status
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $purge_after_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Folder|null $folder
 */
class DriveFile extends Model
{
    /** @use HasFactory<DriveFileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'folder_id',
        'space',
        'name',
        'object_key',
        'thumbnail_key',
        'thumbnail_preset',
        'thumbnail_mime',
        'thumbnail_size_bytes',
        'thumbnail_status',
        'size_bytes',
        'sha256',
        'mime_type',
        'category',
        'media_taken_at',
        'source_device_id',
        'source_asset_id',
        'source_revision',
        'status',
        'deleted_at',
        'purge_after_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'space' => Space::class,
            'category' => FileCategory::class,
            'size_bytes' => 'integer',
            'thumbnail_size_bytes' => 'integer',
            'media_taken_at' => 'datetime',
            'deleted_at' => 'datetime',
            'purge_after_at' => 'datetime',
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
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function hasPreview(): bool
    {
        return in_array($this->category, [FileCategory::Image, FileCategory::Video], true)
            || $this->mime_type === 'application/pdf';
    }

    public function resolvedThumbnailPreset(): ThumbnailPreset
    {
        if (is_string($this->thumbnail_preset) && $this->thumbnail_preset !== '') {
            $parsed = ThumbnailPreset::tryFrom($this->thumbnail_preset);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return ThumbnailPreset::fromFile($this->mime_type, $this->name, $this->category);
    }
}
