<?php

namespace App\Services;

use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Models\ApiCredential;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\SourceMarker;
use App\Models\User;
use App\Models\VaultConfig;
use App\Models\VaultSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VaultService
{
    public function __construct(
        public AuditService $audit,
        public FileCatalogService $catalog,
    ) {}

    public function setPassphrase(User $user, string $passphrase, ?string $requestId = null, string $action = 'vault.passphrase_updated'): VaultConfig
    {
        /** @var VaultConfig $config */
        $config = DB::transaction(function () use ($user, $passphrase): VaultConfig {
            $saved = VaultConfig::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'passphrase_hash' => Hash::make($passphrase),
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'passphrase_updated_at' => now(),
                ],
            );

            VaultSession::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return $saved;
        });

        $this->audit->recordEvent($user->id, $action, 'success', [], $requestId);

        return $config;
    }

    /**
     * @return array{session: VaultSession, plain_token: string}
     */
    public function unlock(
        User $user,
        ApiCredential $credential,
        string $passphrase,
        ?string $requestId = null,
    ): array {
        $config = VaultConfig::query()->where('user_id', $user->id)->first();
        if ($config === null) {
            throw new ApiErrorException(
                'vault_not_configured',
                'Frasa sandi Folder Privat belum diatur di dashboard.',
                412,
            );
        }

        if ($config->locked_until !== null && $config->locked_until->isFuture()) {
            $retryAfter = (int) now()->diffInSeconds($config->locked_until);

            throw new ApiErrorException(
                'vault_rate_limited',
                'Terlalu banyak percobaan salah. Coba lagi beberapa menit lagi.',
                429,
                ['retry_after_seconds' => max(1, $retryAfter)],
            );
        }

        if (! Hash::check($passphrase, $config->passphrase_hash)) {
            $config->failed_attempts += 1;
            $maxAttempts = (int) config('gunydrive.vault_max_attempts', 5);
            if ($config->failed_attempts >= $maxAttempts) {
                $config->locked_until = now()->addSeconds((int) config('gunydrive.vault_lockout_seconds', 300));
                $config->failed_attempts = 0;
            }
            $config->save();

            $this->audit->recordEvent($user->id, 'vault.unlock', 'denied', [
                'credential_id' => $credential->id,
            ], $requestId);

            throw new ApiErrorException('vault_invalid_passphrase', 'Frasa sandi Folder Privat salah.', 403);
        }

        $config->failed_attempts = 0;
        $config->locked_until = null;
        $config->save();

        $plainToken = 'gd_vlt_'.Str::random(48);
        $ttl = (int) config('gunydrive.vault_idle_timeout_seconds', 300);

        $session = VaultSession::query()->create([
            'user_id' => $user->id,
            'api_credential_id' => $credential->id,
            'token_hash' => hash('sha256', $plainToken),
            'last_activity_at' => now(),
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $this->audit->recordEvent($user->id, 'vault.unlock', 'success', [
            'credential_id' => $credential->id,
        ], $requestId);

        return [
            'session' => $session,
            'plain_token' => $plainToken,
        ];
    }

    public function validateSession(
        ApiCredential $credential,
        ?string $plainVaultToken,
        bool $touchActivity = true,
    ): VaultSession {
        if ($plainVaultToken === null || trim($plainVaultToken) === '') {
            throw new ApiErrorException('vault_locked', 'Folder Privat terkunci. Masukkan frasa sandi terlebih dahulu.', 403);
        }

        $session = VaultSession::query()
            ->where('token_hash', hash('sha256', trim($plainVaultToken)))
            ->where('api_credential_id', $credential->id)
            ->where('user_id', $credential->user_id)
            ->first();

        if ($session === null || ! $session->isActive()) {
            throw new ApiErrorException('vault_locked', 'Sesi Folder Privat sudah berakhir atau dikunci.', 403);
        }

        if ($touchActivity) {
            $ttl = (int) config('gunydrive.vault_idle_timeout_seconds', 300);
            $session->forceFill([
                'last_activity_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ])->save();
        }

        return $session;
    }

    public function lock(VaultSession $session, ?string $requestId = null): void
    {
        $session->revoked_at = now();
        $session->save();

        $this->audit->recordEvent($session->user_id, 'vault.lock', 'success', [
            'credential_id' => $session->api_credential_id,
        ], $requestId);
    }

    public function moveFileIntoVault(
        User $user,
        DriveFile $file,
        ?string $targetVaultFolderId = null,
        ?string $requestId = null,
    ): DriveFile {
        if ($file->user_id !== $user->id || $file->space !== Space::Regular || $file->deleted_at !== null) {
            throw new ApiErrorException('file_not_found', 'Berkas biasa tidak ditemukan.', 404);
        }

        if ($targetVaultFolderId !== null) {
            $folderOk = Folder::query()
                ->whereKey($targetVaultFolderId)
                ->where('user_id', $user->id)
                ->where('space', Space::Private)
                ->whereNull('deleted_at')
                ->exists();

            if (! $folderOk) {
                throw new ApiErrorException('folder_not_found', 'Folder Privat tujuan tidak ditemukan.', 404);
            }
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $newObjectKey = 'objects/private/'.Str::uuid()->toString();
        $oldObjectKey = $file->object_key;
        $oldThumbKey = $file->thumbnail_key;
        $newThumbKey = null;

        if ($disk->exists($oldObjectKey)) {
            $disk->move($oldObjectKey, $newObjectKey);
        }

        if ($oldThumbKey !== null && $disk->exists($oldThumbKey)) {
            $newThumbKey = 'thumbnails/private/'.Str::uuid()->toString().'.jpg';
            $disk->move($oldThumbKey, $newThumbKey);
        }

        $updated = DB::transaction(function () use ($user, $file, $targetVaultFolderId, $newObjectKey, $newThumbKey): DriveFile {
            $file->space = Space::Private;
            $file->folder_id = $targetVaultFolderId;
            $file->object_key = $newObjectKey;
            $file->thumbnail_key = $newThumbKey;
            $file->name = $this->catalog->uniqueFileName($user, Space::Private, $targetVaultFolderId, $file->name, $file->id);
            $file->save();

            if ($file->source_asset_id !== null && $file->source_revision !== null) {
                SourceMarker::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'device_id' => $file->source_device_id,
                        'source_asset_id' => $file->source_asset_id,
                        'source_revision' => $file->source_revision,
                    ],
                    [
                        'disposition' => 'moved_to_private',
                        'drive_file_id' => $file->id,
                    ],
                );
            }

            return $file;
        });

        $this->audit->recordEvent($user->id, 'vault.file_moved_in', 'success', [
            'file_id' => $updated->id,
        ], $requestId);

        return $updated;
    }
}
