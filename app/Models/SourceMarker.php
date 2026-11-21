<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $device_id
 * @property string $source_asset_id
 * @property string $source_revision
 * @property string $disposition
 * @property string|null $drive_file_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SourceMarker extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'source_asset_id',
        'source_revision',
        'disposition',
        'drive_file_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
