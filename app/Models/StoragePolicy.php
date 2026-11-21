<?php

namespace App\Models;

use Database\Factories\StoragePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $quota_bytes
 * @property int $max_file_bytes
 * @property int $physical_reserve_bytes
 * @property int $trash_retention_days
 * @property int $used_bytes
 * @property int $reserved_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class StoragePolicy extends Model
{
    /** @use HasFactory<StoragePolicyFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quota_bytes',
        'max_file_bytes',
        'physical_reserve_bytes',
        'trash_retention_days',
        'used_bytes',
        'reserved_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer',
            'max_file_bytes' => 'integer',
            'physical_reserve_bytes' => 'integer',
            'trash_retention_days' => 'integer',
            'used_bytes' => 'integer',
            'reserved_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availableQuotaBytes(): int
    {
        return max(0, $this->quota_bytes - $this->used_bytes - $this->reserved_bytes);
    }
}
