<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property string|null $google_id
 * @property string|null $avatar_path
 * @property string|null $biometric_token_hash
 * @property bool $is_admin
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StoragePolicy|null $storagePolicy
 * @property-read VaultConfig|null $vaultConfig
 */
#[Fillable(['name', 'email', 'password', 'avatar_path'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'biometric_token_hash'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * @var list<string>
     */
    protected $appends = ['avatar'];

    public static function availableUsername(string $email): string
    {
        $base = Str::slug(strstr($email, '@', true) ?: 'user') ?: 'user';
        $username = $base;
        while (static::where('username', $username)->exists()) {
            $username = $base.'-'.strtolower(Str::random(6));
        }

        return $username;
    }

    public function issueEmailVerificationCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = (int) config('auth.verification.expire', 60);

        Cache::put(
            'email-verify-otp:'.$this->getKey(),
            hash('sha256', $code),
            now()->addMinutes($minutes),
        );

        return $code;
    }

    public function verifyEmailCode(string $code): bool
    {
        $cacheKey = 'email-verify-otp:'.$this->getKey();
        $expectedHash = Cache::get($cacheKey);

        if (! is_string($expectedHash) || ! hash_equals($expectedHash, hash('sha256', trim($code)))) {
            return false;
        }

        Cache::forget($cacheKey);

        if (! $this->hasVerifiedEmail()) {
            $this->markEmailAsVerified();
            event(new Verified($this));
        }

        return true;
    }

    public function avatarUrl(): ?string
    {
        if (! is_string($this->avatar_path) || $this->avatar_path === '') {
            return null;
        }

        if (! Storage::disk('public')->exists($this->avatar_path)) {
            return null;
        }

        $version = $this->updated_at?->getTimestamp() ?? 1;
        $path = '/avatars/'.$this->getKey().'?v='.$version;

        if (! app()->runningInConsole() && request()->getHost() !== '') {
            return rtrim(request()->getSchemeAndHttpHost(), '/').$path;
        }

        return url($path);
    }

    public function getAvatarAttribute(): ?string
    {
        return $this->avatarUrl();
    }

    public function storeRemoteAvatar(?string $remoteUrl): void
    {
        if (! is_string($remoteUrl) || trim($remoteUrl) === '') {
            return;
        }

        try {
            $response = Http::timeout(8)->get(trim($remoteUrl));
            if (! $response->successful()) {
                return;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > 5 * 1024 * 1024) {
                return;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            $ext = str_contains($contentType, 'png')
                ? 'png'
                : (str_contains($contentType, 'webp') ? 'webp' : 'jpg');

            $relativePath = 'avatars/user-'.$this->getKey().'.'.$ext;
            Storage::disk('public')->put($relativePath, $body);

            $this->forceFill(['avatar_path' => $relativePath])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function ensureServerAvatar(): void
    {
        if (is_string($this->avatar_path) && $this->avatar_path !== '' && Storage::disk('public')->exists($this->avatar_path)) {
            return;
        }

        $gravatarUrl = 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($this->email))).'?d=404&s=256';
        $this->storeRemoteAvatar($gravatarUrl);

        if (is_string($this->avatar_path) && $this->avatar_path !== '' && Storage::disk('public')->exists($this->avatar_path)) {
            return;
        }

        if (! function_exists('imagecreatetruecolor')) {
            return;
        }

        $size = 256;
        $img = imagecreatetruecolor($size, $size);
        if ($img === false) {
            return;
        }

        for ($y = 0; $y < $size; $y++) {
            $ratio = $y / $size;
            $r = (int) round(79 + (139 - 79) * $ratio);
            $g = (int) round(70 + (92 - 70) * $ratio);
            $b = (int) round(229 + (246 - 229) * $ratio);
            $color = imagecolorallocate($img, $r, $g, $b);
            if ($color !== false) {
                imageline($img, 0, $y, $size, $y, $color);
            }
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        if ($white !== false) {
            imagefilledellipse($img, 128, 98, 76, 76, $white);
            imagefilledarc($img, 128, 224, 156, 124, 180, 360, $white, IMG_ARC_PIE);
        }

        ob_start();
        imagepng($img);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($img);

        if ($pngBytes !== '') {
            $relativePath = 'avatars/user-'.$this->getKey().'.png';
            Storage::disk('public')->put($relativePath, $pngBytes);
            $this->forceFill(['avatar_path' => $relativePath])->save();
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return HasOne<StoragePolicy, $this>
     */
    public function storagePolicy(): HasOne
    {
        return $this->hasOne(StoragePolicy::class);
    }

    /**
     * @return HasOne<VaultConfig, $this>
     */
    public function vaultConfig(): HasOne
    {
        return $this->hasOne(VaultConfig::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    /**
     * @return HasMany<DriveFile, $this>
     */
    public function driveFiles(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }

    /**
     * @return HasMany<IntegrationEndpoint, $this>
     */
    public function integrationEndpoints(): HasMany
    {
        return $this->hasMany(IntegrationEndpoint::class);
    }

    /**
     * @return HasMany<ApiCredential, $this>
     */
    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    /**
     * @return HasMany<UploadSession, $this>
     */
    public function uploadSessions(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }
}
