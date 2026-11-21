<?php

namespace App\Models;

use App\Enums\IntegrationTemplate;
use Carbon\CarbonInterface;
use Database\Factories\IntegrationEndpointFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $user_id
 * @property string $slug
 * @property string $name
 * @property IntegrationTemplate $template
 * @property string|null $folder_id
 * @property bool $is_active
 * @property CarbonInterface $published_at
 * @property CarbonInterface|null $disabled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Folder|null $folder
 */
class IntegrationEndpoint extends Model
{
    /** @use HasFactory<IntegrationEndpointFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'template',
        'folder_id',
        'is_active',
        'published_at',
        'disabled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => IntegrationTemplate::class,
            'is_active' => 'boolean',
            'published_at' => 'datetime',
            'disabled_at' => 'datetime',
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

    /**
     * @return HasMany<ApiCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }
}
