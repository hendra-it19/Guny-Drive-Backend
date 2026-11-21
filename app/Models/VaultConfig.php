<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\VaultConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $passphrase_hash
 * @property int $failed_attempts
 * @property CarbonInterface|null $locked_until
 * @property CarbonInterface|null $passphrase_updated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 */
class VaultConfig extends Model
{
    /** @use HasFactory<VaultConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'passphrase_hash',
        'failed_attempts',
        'locked_until',
        'passphrase_updated_at',
    ];

    protected $hidden = [
        'passphrase_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'passphrase_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
