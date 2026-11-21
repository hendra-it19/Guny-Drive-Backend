<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $job_name
 * @property Carbon $last_ran_at
 * @property string $status
 * @property string|null $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkerHeartbeat extends Model
{
    protected $fillable = [
        'job_name',
        'last_ran_at',
        'status',
        'summary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_ran_at' => 'datetime',
        ];
    }
}
