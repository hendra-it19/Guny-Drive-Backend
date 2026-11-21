<?php

namespace App\Services;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\UploadStatus;
use App\Exceptions\ApiErrorException;
use App\Models\ApiCredential;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\SourceMarker;
use App\Models\UploadSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadService
{
    public function __construct(
        public StorageQuotaService $quota,
        public FileCatalogService $catalog,
        public ThumbnailService $thumbnails,
    ) {}

    /**
     * @return array{session?: UploadSession, skipped?: bool, reason?: string, existing_file?: DriveFile}
     */
    public function createOrResumeSession(
        User $user,
        ApiCredential $credential,
        string $idempotencyKey,
        string $filename,
        int $totalSizeBytes,
        string $expectedSha256,
        Space $space,
        ?string $folderId = null,
        ?string $mimeType = null,
        ?CarbonInterface $mediaTakenAt = null,
        ?string $sourceAssetId = null,
        ?string $sourceRevision = null,
        bool $autoBackup = false,
        bool $deduplicate = false,
    ): array {
        if ($deduplicate) {
            $match = $this->matchingBackup($user->id, $space, $folderId, strtolower($expectedSha256), $totalSizeBytes);
            if ($match !== null) {
                return ['existing_file' => $match];
            }
            $idempotencyKey = 'backup:'.hash('sha256', json_encode([$space->value, $folderId, strtolower($expectedSha256)], JSON_THROW_ON_ERROR));
        }

        $existing = UploadSession::query()
            ->where('api_credential_id', $credential->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (! $deduplicate && $existing->status === UploadStatus::Completed && $existing->completedFile !== null) {
                return ['session' => $existing, 'existing_file' => $existing->completedFile];
            }

            if ($existing->status === UploadStatus::Open && $existing->expires_at->isFuture()) {
                return ['session' => $existing];
            }

            if ($deduplicate) {
                if ($existing->status === UploadStatus::Open) {
                    $this->expireSession($existing);
                }
                UploadSession::query()->whereKey($existing->id)->where('idempotency_key', $idempotencyKey)
                    ->update(['idempotency_key' => 'closed:'.$existing->id]);
            } else {
                throw new ApiErrorException('upload_session_closed', 'Sesi unggah sudah berakhir atau dibatalkan.', 409);
            }
        }

        if ($autoBackup && $sourceAssetId !== null && $sourceRevision !== null) {
            $marker = SourceMarker::query()
                ->where('user_id', $user->id)
                ->where('device_id', $credential->device_id)
                ->where('source_asset_id', $sourceAssetId)
                ->where('source_revision', $sourceRevision)
                ->first();

            if ($marker !== null) {
                return [
                    'skipped' => true,
                    'reason' => $marker->disposition,
                ];
            }
        }

        if ($folderId !== null) {
            $folderOk = Folder::query()
                ->whereKey($folderId)
                ->where('user_id', $user->id)
                ->where('space', $space)
                ->whereNull('deleted_at')
                ->exists();

            if (! $folderOk) {
                throw new ApiErrorException('folder_not_found', 'Folder tujuan tidak ditemukan.', 404);
            }
        }

        $this->quota->assertAndReserve($user, $totalSizeBytes);

        $stagingKey = 'staging/'.Str::uuid()->toString().'.part';
        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $disk->put($stagingKey, '');

        try {
            $session = UploadSession::query()->create([
                'user_id' => $user->id,
                'api_credential_id' => $credential->id,
                'device_id' => $credential->device_id,
                'space' => $space,
                'folder_id' => $folderId,
                'idempotency_key' => $idempotencyKey,
                'filename' => trim($filename),
                'mime_type' => $mimeType,
                'total_size_bytes' => $totalSizeBytes,
                'expected_sha256' => strtolower($expectedSha256),
                'received_offset' => 0,
                'reserved_bytes' => $totalSizeBytes,
                'staging_key' => $stagingKey,
                'media_taken_at' => $mediaTakenAt,
                'source_asset_id' => $sourceAssetId,
                'source_revision' => $sourceRevision,
                'status' => UploadStatus::Open,
                'expires_at' => now()->addHours((int) config('gunydrive.upload_session_ttl_hours', 24)),
            ]);
        } catch (\Throwable $e) {
            $this->quota->releaseReservation($user, $totalSizeBytes);
            $disk->delete($stagingKey);

            throw $e;
        }

        return ['session' => $session];
    }

    public function appendChunk(
        UploadSession $session,
        int $offset,
        string $binaryChunk,
        ?string $chunkSha256 = null,
    ): UploadSession {
        $lock = Cache::lock('upload_session:'.$session->id, 15);

        if (! $lock->get()) {
            throw new ApiErrorException('upload_busy', 'Potongan lain untuk sesi ini sedang diproses.', 409);
        }

        try {
            $fresh = UploadSession::query()->findOrFail($session->id);

            if ($fresh->status !== UploadStatus::Open) {
                throw new ApiErrorException('upload_session_closed', 'Sesi unggah sudah tidak aktif.', 409);
            }

            if ($fresh->expires_at->isPast()) {
                $this->expireSession($fresh);

                throw new ApiErrorException('upload_session_expired', 'Sesi unggah telah kedaluwarsa.', 410);
            }

            if ($offset !== $fresh->received_offset) {
                throw new ApiErrorException(
                    'offset_conflict',
                    'Posisi potongan tidak cocok dengan offset terakhir di server.',
                    409,
                    ['expected_offset' => $fresh->received_offset],
                );
            }

            $chunkLen = strlen($binaryChunk);
            if ($fresh->received_offset + $chunkLen > $fresh->total_size_bytes) {
                throw new ApiErrorException('chunk_exceeds_total', 'Potongan melebihi ukuran berkas yang dideklarasikan.', 413);
            }

            if ($chunkSha256 !== null && $chunkSha256 !== '') {
                $actualChunkHash = hash('sha256', $binaryChunk);
                if (! hash_equals(strtolower($chunkSha256), $actualChunkHash)) {
                    throw new ApiErrorException('chunk_checksum_mismatch', 'Checksum potongan tidak cocok.', 422);
                }
            }

            $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
            $path = $disk->path($fresh->staging_key);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $handle = fopen($path, 'c+b');
            if ($handle === false) {
                throw new ApiErrorException('storage_io_error', 'Gagal menulis potongan berkas ke disk.', 500);
            }

            try {
                fseek($handle, $fresh->received_offset);
                if ($chunkLen > 0) {
                    fwrite($handle, $binaryChunk);
                }
                fflush($handle);
            } finally {
                fclose($handle);
            }

            $fresh->received_offset += $chunkLen;
            $fresh->save();

            return $fresh;
        } finally {
            $lock->release();
        }
    }

    public function completeSession(UploadSession $session): DriveFile
    {
        $lock = Cache::lock('upload_session:'.$session->id, 30);

        if (! $lock->get()) {
            throw new ApiErrorException('upload_busy', 'Finalisasi unggahan sedang berlangsung.', 409);
        }

        try {
            /** @var UploadSession $fresh */
            $fresh = UploadSession::query()->with(['user', 'completedFile'])->findOrFail($session->id);

            if ($fresh->status === UploadStatus::Completed && $fresh->completedFile !== null) {
                return $fresh->completedFile;
            }

            if ($fresh->status !== UploadStatus::Open) {
                throw new ApiErrorException('upload_session_closed', 'Sesi unggah sudah ditutup atau gagal.', 409);
            }

            if ($fresh->expires_at->isPast()) {
                $this->expireSession($fresh);

                throw new ApiErrorException('upload_session_expired', 'Sesi unggah telah kedaluwarsa.', 410);
            }

            $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
            $stagingPath = $disk->path($fresh->staging_key);

            if ($fresh->total_size_bytes === 0 && ! is_file($stagingPath)) {
                $disk->put($fresh->staging_key, '');
            }

            if (! is_file($stagingPath)) {
                $this->failSession($fresh, 'staging_missing');

                throw new ApiErrorException('staging_missing', 'Data unggahan sementara tidak ditemukan.', 409);
            }

            $actualSize = (int) filesize($stagingPath);
            if ($actualSize !== $fresh->total_size_bytes || $fresh->received_offset !== $fresh->total_size_bytes) {
                throw new ApiErrorException(
                    'upload_incomplete',
                    'Unggahan belum lengkap. Kirim sisa potongan terlebih dahulu.',
                    409,
                    ['received_offset' => $fresh->received_offset, 'total_size_bytes' => $fresh->total_size_bytes],
                );
            }

            $actualHash = hash_file('sha256', $stagingPath);
            if ($actualHash === false || ! hash_equals($fresh->expected_sha256, strtolower($actualHash))) {
                $this->failSession($fresh, 'checksum_mismatch');

                throw new ApiErrorException('checksum_mismatch', 'Checksum SHA-256 berkas tidak cocok.', 422);
            }

            $detectedMime = $this->detectMime($stagingPath, $fresh->mime_type);
            $category = FileCategory::fromMimeAndExtension($detectedMime, $fresh->filename);

            $objectPrefix = $fresh->space === Space::Private ? 'objects/private' : 'objects/regular';
            $objectKey = $objectPrefix.'/'.Str::uuid()->toString();
            $disk->move($fresh->staging_key, $objectKey);

            $preset = $this->thumbnails->resolvePreset($detectedMime, $fresh->filename, $category);
            $thumbnailKey = null;
            $thumbnailMime = 'image/svg+xml';
            $thumbnailSizeBytes = 0;
            $thumbnailStatus = 'default';

            if ($preset->isMedia() && $actualSize > 0) {
                $generated = $this->thumbnails->generateOptimizedForObject($objectKey, $fresh->space, $category);
                if ($generated !== null) {
                    $thumbnailKey = $generated['key'];
                    $thumbnailMime = $generated['mime'];
                    $thumbnailSizeBytes = $generated['size_bytes'];
                    $thumbnailStatus = 'generated';
                } else {
                    $thumbnailStatus = 'failed';
                }
            }

            /** @var DriveFile $file */
            $file = DB::transaction(function () use (
                $fresh,
                $objectKey,
                $thumbnailKey,
                $preset,
                $thumbnailMime,
                $thumbnailSizeBytes,
                $thumbnailStatus,
                $actualSize,
                $actualHash,
                $detectedMime,
                $category,
            ): DriveFile {
                if (str_starts_with($fresh->idempotency_key, 'backup:')) {
                    User::query()->whereKey($fresh->user_id)->lockForUpdate()->firstOrFail();
                    $match = $this->matchingBackup($fresh->user_id, $fresh->space, $fresh->folder_id, strtolower($actualHash), $actualSize);
                    if ($match !== null) {
                        $this->quota->releaseReservation($fresh->user, $fresh->reserved_bytes);
                        $fresh->update(['reserved_bytes' => 0, 'status' => UploadStatus::Completed, 'completed_file_id' => $match->id]);

                        return $match;
                    }
                }

                $uniqueName = $this->catalog->uniqueFileName(
                    $fresh->user,
                    $fresh->space,
                    $fresh->folder_id,
                    $fresh->filename,
                );

                $created = DriveFile::query()->create([
                    'user_id' => $fresh->user_id,
                    'folder_id' => $fresh->folder_id,
                    'space' => $fresh->space,
                    'name' => $uniqueName,
                    'object_key' => $objectKey,
                    'thumbnail_key' => $thumbnailKey,
                    'thumbnail_preset' => $preset->value,
                    'thumbnail_mime' => $thumbnailMime,
                    'thumbnail_size_bytes' => $thumbnailSizeBytes,
                    'thumbnail_status' => $thumbnailStatus,
                    'size_bytes' => $actualSize,
                    'sha256' => strtolower($actualHash),
                    'mime_type' => $detectedMime,
                    'category' => $category,
                    'media_taken_at' => $fresh->media_taken_at,
                    'source_device_id' => $fresh->device_id,
                    'source_asset_id' => $fresh->source_asset_id,
                    'source_revision' => $fresh->source_revision,
                    'status' => 'ready',
                ]);

                $this->quota->convertReservationToUsage($fresh->user, $fresh->reserved_bytes, $actualSize);

                $fresh->reserved_bytes = 0;
                $fresh->status = UploadStatus::Completed;
                $fresh->completed_file_id = $created->id;
                $fresh->save();

                if ($fresh->source_asset_id !== null && $fresh->source_revision !== null) {
                    SourceMarker::query()->updateOrCreate(
                        [
                            'user_id' => $fresh->user_id,
                            'device_id' => $fresh->device_id,
                            'source_asset_id' => $fresh->source_asset_id,
                            'source_revision' => $fresh->source_revision,
                        ],
                        [
                            'disposition' => 'uploaded',
                            'drive_file_id' => $created->id,
                        ],
                    );
                }

                if ($fresh->device_id !== null) {
                    DB::table('devices')
                        ->where('id', $fresh->device_id)
                        ->update(['last_backup_at' => now()]);
                }

                return $created;
            });

            if ($file->object_key !== $objectKey) {
                $disk->delete($objectKey);
                if ($thumbnailKey !== null) {
                    $disk->delete($thumbnailKey);
                }
            }

            return $file;
        } finally {
            $lock->release();
        }
    }

    private function matchingBackup(int $userId, Space $space, ?string $folderId, string $hash, int $bytes): ?DriveFile
    {
        return DriveFile::query()->where('user_id', $userId)->where('space', $space)
            ->where('folder_id', $folderId)->whereNull('deleted_at')->where('status', 'ready')
            ->where('sha256', $hash)->where('size_bytes', $bytes)->first();
    }

    public function abortSession(UploadSession $session): void
    {
        if ($session->status !== UploadStatus::Open) {
            return;
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if ($disk->exists($session->staging_key)) {
            $disk->delete($session->staging_key);
        }

        DB::transaction(function () use ($session): void {
            /** @var UploadSession $fresh */
            $fresh = UploadSession::query()->with('user')->findOrFail($session->id);
            if ($fresh->reserved_bytes > 0) {
                $this->quota->releaseReservation($fresh->user, $fresh->reserved_bytes);
                $fresh->reserved_bytes = 0;
            }
            $fresh->status = UploadStatus::Aborted;
            $fresh->save();
        });
    }

    public function cleanupExpiredSessions(): int
    {
        $expired = UploadSession::query()
            ->with('user')
            ->where('status', UploadStatus::Open)
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expired as $session) {
            $this->expireSession($session);
            $count++;
        }

        return $count;
    }

    private function expireSession(UploadSession $session): void
    {
        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if ($disk->exists($session->staging_key)) {
            $disk->delete($session->staging_key);
        }

        DB::transaction(function () use ($session): void {
            /** @var UploadSession $fresh */
            $fresh = UploadSession::query()->with('user')->findOrFail($session->id);
            if ($fresh->reserved_bytes > 0) {
                $this->quota->releaseReservation($fresh->user, $fresh->reserved_bytes);
                $fresh->reserved_bytes = 0;
            }
            $fresh->status = UploadStatus::Expired;
            $fresh->save();
        });
    }

    private function failSession(UploadSession $session, string $reason): void
    {
        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if ($disk->exists($session->staging_key)) {
            $disk->delete($session->staging_key);
        }

        DB::transaction(function () use ($session, $reason): void {
            /** @var UploadSession $fresh */
            $fresh = UploadSession::query()->with('user')->findOrFail($session->id);
            if ($fresh->reserved_bytes > 0) {
                $this->quota->releaseReservation($fresh->user, $fresh->reserved_bytes);
                $fresh->reserved_bytes = 0;
            }
            $fresh->status = UploadStatus::Failed;
            $fresh->failure_reason = $reason;
            $fresh->save();
        });
    }

    private function detectMime(string $path, ?string $fallback): string
    {
        if (function_exists('finfo_open') && filesize($path) > 0) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                    return $detected;
                }
            }
        }

        if ($fallback !== null && trim($fallback) !== '') {
            return trim($fallback);
        }

        return 'application/octet-stream';
    }
}
