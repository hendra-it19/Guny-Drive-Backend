<?php

namespace App\Models;

use App\Enums\Space;
use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $api_credential_id
 * @property string|null $device_id
 * @property Space $space
 * @property string|null $folder_id
 * @property string $idempotency_key
 * @property string $filename
 * @property string|null $mime_type
 * @property int $total_size_bytes
 * @property string $expected_sha256
 * @property int $received_offset
 * @property int $reserved_bytes
 * @property string $staging_key
 * @property Carbon|null $media_taken_at
 * @property string|null $source_asset_id
 * @property string|null $source_revision
 * @property UploadStatus $status
 * @property string|null $completed_file_id
 * @property string|null $failure_reason
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read DriveFile|null $completedFile
 */
class UploadSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'api_credential_id',
        'device_id',
        'space',
        'folder_id',
        'idempotency_key',
        'filename',
        'mime_type',
        'total_size_bytes',
        'expected_sha256',
        'received_offset',
        'reserved_bytes',
        'staging_key',
        'media_taken_at',
        'source_asset_id',
        'source_revision',
        'status',
        'completed_file_id',
        'failure_reason',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'space' => Space::class,
            'status' => UploadStatus::class,
            'total_size_bytes' => 'integer',
            'received_offset' => 'integer',
            'reserved_bytes' => 'integer',
            'media_taken_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<DriveFile, $this>
     */
    public function completedFile(): BelongsTo
    {
        return $this->belongsTo(DriveFile::class, 'completed_file_id');
    }
}
