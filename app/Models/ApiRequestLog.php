<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $api_credential_id
 * @property string $request_id
 * @property string $method
 * @property string $route_template
 * @property int $status_code
 * @property string|null $error_code
 * @property int $duration_ms
 * @property Carbon $created_at
 */
class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'api_credential_id',
        'request_id',
        'method',
        'route_template',
        'status_code',
        'error_code',
        'duration_ms',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
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
