<?php

namespace App\Services;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\ThumbnailPreset;
use App\Exceptions\ApiErrorException;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\SourceMarker;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileCatalogService
{
    public function __construct(public StorageQuotaService $quota) {}

    public function createFolder(User $user, Space $space, string $name, ?string $parentId = null): Folder
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new ApiErrorException('invalid_folder_name', 'Nama folder tidak boleh kosong.', 422);
        }

        if ($parentId !== null) {
            $parent = Folder::query()
                ->whereKey($parentId)
                ->where('user_id', $user->id)
                ->where('space', $space)
                ->whereNull('deleted_at')
                ->first();

            if ($parent === null) {
                throw new ApiErrorException('folder_not_found', 'Folder induk tidak ditemukan.', 404);
            }
        }

        $finalName = $this->uniqueFolderName($user, $space, $parentId, $cleanName);

        return Folder::query()->create([
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'space' => $space,
            'name' => $finalName,
        ]);
    }

    public function updateFolder(
        User $user,
        Folder $folder,
        ?string $name,
        ?string $parentId,
        bool $hasParentChange,
    ): Folder {
        if ($folder->user_id !== $user->id || $folder->deleted_at !== null) {
            throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
        }

        if ($hasParentChange) {
            if ($parentId === $folder->id) {
                throw new ApiErrorException('folder_cycle', 'Folder tidak dapat dipindahkan ke dalam dirinya sendiri.', 422);
            }

            if ($parentId !== null) {
                $targetParent = Folder::query()
                    ->whereKey($parentId)
                    ->where('user_id', $user->id)
                    ->where('space', $folder->space)
                    ->whereNull('deleted_at')
                    ->first();

                if ($targetParent === null) {
                    throw new ApiErrorException('folder_not_found', 'Folder tujuan tidak ditemukan.', 404);
                }

                if ($this->isDescendant($targetParent, $folder->id)) {
                    throw new ApiErrorException('folder_cycle', 'Folder tidak dapat dipindahkan ke dalam subfoldernya sendiri.', 422);
                }
            }

            $folder->parent_id = $parentId;
        }

        if ($name !== null) {
            $cleanName = trim($name);
            if ($cleanName === '') {
                throw new ApiErrorException('invalid_folder_name', 'Nama folder tidak boleh kosong.', 422);
            }
            $folder->name = $this->uniqueFolderName($user, $folder->space, $folder->parent_id, $cleanName, $folder->id);
        }

        $folder->save();

        return $folder;
    }

    public function deleteFolder(User $user, Folder $folder): void
    {
        if ($folder->user_id !== $user->id || $folder->deleted_at !== null) {
            throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
        }

        DB::transaction(function () use ($user, $folder): void {
            $folderIds = $this->collectSubtreeFolderIds($folder);

            $files = DriveFile::query()
                ->where('user_id', $user->id)
                ->where('space', $folder->space)
                ->whereIn('folder_id', $folderIds)
                ->whereNull('deleted_at')
                ->get();

            foreach ($files as $file) {
                $this->trashFile($user, $file);
            }

            Folder::query()
                ->whereIn('id', $folderIds)
                ->update(['deleted_at' => now()]);
        });
    }

    /**
     * @return array{folders: list<array<string, mixed>>, files: list<array<string, mixed>>, breadcrumbs: list<array{id: string, name: string}>, next_cursor: string|null}
     */
    public function listDirectory(
        User $user,
        Space $space,
        ?string $folderId,
        ?string $query,
        ?string $category,
        string $sort = 'date',
        ?string $cursor = null,
        int $perPage = 40,
    ): array {
        $search = $query !== null ? trim($query) : '';
        $breadcrumbs = [];

        if ($folderId !== null) {
            $current = Folder::query()
                ->whereKey($folderId)
                ->where('user_id', $user->id)
                ->where('space', $space)
                ->whereNull('deleted_at')
                ->first();

            if ($current === null) {
                throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
            }

            $breadcrumbs = $this->buildBreadcrumbs($current);
        }

        $folderQuery = Folder::query()
            ->where('user_id', $user->id)
            ->where('space', $space)
            ->whereNull('deleted_at')
            ->withCount(['files as files_count' => function ($q): void {
                $q->whereNull('deleted_at');
            }]);

        if ($search !== '') {
            $folderQuery->where('name', 'like', '%'.$search.'%');
        } else {
            $folderQuery->where('parent_id', $folderId);
        }

        $folders = $folderQuery
            ->orderBy('name')
            ->get()
            ->map(fn (Folder $f): array => $this->serializeFolder($f))
            ->values()
            ->all();

        $fileQuery = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', $space)
            ->whereNull('deleted_at');

        if ($search !== '') {
            $fileQuery->where('name', 'like', '%'.$search.'%');
        } else {
            $fileQuery->where('folder_id', $folderId);
        }

        if ($category !== null && $category !== '' && $category !== 'all') {
            if ($category === 'media') {
                $fileQuery->whereIn('category', [FileCategory::Image->value, FileCategory::Video->value]);
            } else {
                $fileQuery->where('category', $category);
            }
        }

        match ($sort) {
            'name' => $fileQuery->orderBy('name')->orderBy('id'),
            'size' => $fileQuery->orderByDesc('size_bytes')->orderBy('id'),
            default => $fileQuery->orderByDesc('created_at')->orderBy('id'),
        };

        $paginated = $fileQuery->cursorPaginate(min(100, max(1, $perPage)), ['*'], 'cursor', $cursor);

        return [
            'folders' => array_values($folders),
            'files' => array_values(collect($paginated->items())->map(fn (DriveFile $f): array => $this->serializeFile($f))->all()),
            'breadcrumbs' => $breadcrumbs,
            'next_cursor' => $paginated->nextCursor()?->encode(),
        ];
    }

    public function updateFile(
        User $user,
        DriveFile $file,
        ?string $name,
        ?string $folderId,
        bool $hasFolderChange,
    ): DriveFile {
        if ($file->user_id !== $user->id || $file->deleted_at !== null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        if ($hasFolderChange) {
            if ($folderId !== null) {
                $targetFolder = Folder::query()
                    ->whereKey($folderId)
                    ->where('user_id', $user->id)
                    ->where('space', $file->space)
                    ->whereNull('deleted_at')
                    ->first();

                if ($targetFolder === null) {
                    throw new ApiErrorException('folder_not_found', 'Folder tujuan tidak valid untuk ruang ini.', 404);
                }
            }

            $file->folder_id = $folderId;
        }

        if ($name !== null) {
            $cleanName = trim($name);
            if ($cleanName === '') {
                throw new ApiErrorException('invalid_filename', 'Nama berkas tidak boleh kosong.', 422);
            }
            $file->name = $this->uniqueFileName($user, $file->space, $file->folder_id, $cleanName, $file->id);
            $file->thumbnail_preset = ThumbnailPreset::fromFile($file->mime_type, $file->name, $file->category)->value;
        } elseif ($hasFolderChange) {
            $file->name = $this->uniqueFileName($user, $file->space, $file->folder_id, $file->name, $file->id);
        }

        $file->save();

        return $file;
    }

    public function copyFile(User $user, DriveFile $source, ?string $targetFolderId = null): DriveFile
    {
        if ($source->user_id !== $user->id || $source->deleted_at !== null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        if ($targetFolderId !== null) {
            $targetFolder = Folder::query()
                ->whereKey($targetFolderId)
                ->where('user_id', $user->id)
                ->where('space', $source->space)
                ->whereNull('deleted_at')
                ->first();

            if ($targetFolder === null) {
                throw new ApiErrorException('folder_not_found', 'Folder tujuan tidak ditemukan.', 404);
            }
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if (! $disk->exists($source->object_key)) {
            throw new ApiErrorException('object_missing', 'Objek berkas sumber tidak ditemukan di disk.', 404);
        }

        $this->quota->assertAndReserve($user, $source->size_bytes);

        $objectPrefix = $source->space === Space::Private ? 'objects/private' : 'objects/regular';
        $newObjectKey = $objectPrefix.'/'.Str::uuid()->toString();
        $newThumbKey = null;

        try {
            $disk->copy($source->object_key, $newObjectKey);
            if ($source->thumbnail_key !== null && $disk->exists($source->thumbnail_key)) {
                $thumbPrefix = $source->space === Space::Private ? 'thumbnails/private' : 'thumbnails/regular';
                $thumbExt = pathinfo($source->thumbnail_key, PATHINFO_EXTENSION) ?: 'jpg';
                $newThumbKey = $thumbPrefix.'/'.Str::uuid()->toString().'.'.$thumbExt;
                $disk->copy($source->thumbnail_key, $newThumbKey);
            }

            /** @var DriveFile $copy */
            $copy = DB::transaction(function () use ($user, $source, $targetFolderId, $newObjectKey, $newThumbKey): DriveFile {
                $uniqueName = $this->uniqueFileName($user, $source->space, $targetFolderId, $source->name);

                $created = DriveFile::query()->create([
                    'user_id' => $user->id,
                    'folder_id' => $targetFolderId,
                    'space' => $source->space,
                    'name' => $uniqueName,
                    'object_key' => $newObjectKey,
                    'thumbnail_key' => $newThumbKey,
                    'thumbnail_preset' => $source->resolvedThumbnailPreset()->value,
                    'thumbnail_mime' => $source->thumbnail_mime,
                    'thumbnail_size_bytes' => $newThumbKey !== null ? $source->thumbnail_size_bytes : 0,
                    'thumbnail_status' => $newThumbKey !== null ? 'generated' : $source->thumbnail_status,
                    'size_bytes' => $source->size_bytes,
                    'sha256' => $source->sha256,
                    'mime_type' => $source->mime_type,
                    'category' => $source->category,
                    'media_taken_at' => $source->media_taken_at,
                    'status' => 'ready',
                ]);

                $this->quota->convertReservationToUsage($user, $source->size_bytes, $source->size_bytes);

                return $created;
            });

            return $copy;
        } catch (\Throwable $e) {
            $this->quota->releaseReservation($user, $source->size_bytes);
            if ($disk->exists($newObjectKey)) {
                $disk->delete($newObjectKey);
            }
            if ($newThumbKey !== null && $disk->exists($newThumbKey)) {
                $disk->delete($newThumbKey);
            }

            throw $e;
        }
    }

    public function copyFolder(User $user, Folder $source, ?string $targetParentId = null, int $depth = 0): Folder
    {
        if ($source->user_id !== $user->id || $source->deleted_at !== null) {
            throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
        }

        if ($depth > 32) {
            throw new ApiErrorException('folder_depth_exceeded', 'Kedalaman folder melebihi batas penyalinan.', 422);
        }

        if ($targetParentId !== null) {
            if ($targetParentId === $source->id) {
                throw new ApiErrorException('folder_cycle', 'Folder tidak dapat disalin ke dalam dirinya sendiri.', 422);
            }

            $targetParent = Folder::query()
                ->whereKey($targetParentId)
                ->where('user_id', $user->id)
                ->where('space', $source->space)
                ->whereNull('deleted_at')
                ->first();

            if ($targetParent === null) {
                throw new ApiErrorException('folder_not_found', 'Folder tujuan tidak ditemukan.', 404);
            }

            if ($this->isDescendant($targetParent, $source->id)) {
                throw new ApiErrorException('folder_cycle', 'Folder tidak dapat disalin ke dalam subfoldernya sendiri.', 422);
            }
        }

        $cloned = $this->createFolder($user, $source->space, $source->name, $targetParentId);

        $childFiles = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', $source->space)
            ->where('folder_id', $source->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($childFiles as $file) {
            $this->copyFile($user, $file, $cloned->id);
        }

        $childFolders = Folder::query()
            ->where('user_id', $user->id)
            ->where('space', $source->space)
            ->where('parent_id', $source->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($childFolders as $childFolder) {
            if ($childFolder->id === $cloned->id) {
                continue;
            }
            $this->copyFolder($user, $childFolder, $cloned->id, $depth + 1);
        }

        return $cloned->loadCount(['files as files_count' => function ($q): void {
            $q->whereNull('deleted_at');
        }]);
    }

    public function trashFile(User $user, DriveFile $file): DriveFile
    {
        if ($file->user_id !== $user->id || $file->deleted_at !== null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        $policy = $this->quota->forUser($user);

        $file->deleted_at = now();
        $file->purge_after_at = now()->addDays($policy->trash_retention_days);
        $file->status = 'trashed';
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
                    'disposition' => 'deleted',
                    'drive_file_id' => $file->id,
                ],
            );
        }

        return $file;
    }

    public function restoreFile(User $user, DriveFile $file): DriveFile
    {
        if ($file->user_id !== $user->id || $file->deleted_at === null) {
            throw new ApiErrorException('file_not_found', 'Berkas di Sampah tidak ditemukan.', 404);
        }

        $targetFolderId = $file->folder_id;
        if ($targetFolderId !== null) {
            $folderActive = Folder::query()
                ->whereKey($targetFolderId)
                ->where('user_id', $user->id)
                ->where('space', $file->space)
                ->whereNull('deleted_at')
                ->exists();

            if (! $folderActive) {
                $targetFolderId = null;
            }
        }

        $file->folder_id = $targetFolderId;
        $file->name = $this->uniqueFileName($user, $file->space, $targetFolderId, $file->name, $file->id);
        $file->deleted_at = null;
        $file->purge_after_at = null;
        $file->status = 'ready';
        $file->save();

        return $file;
    }

    public function purgeFile(User $user, DriveFile $file): void
    {
        if ($file->user_id !== $user->id) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $objectKey = $file->object_key;
        $thumbKey = $file->thumbnail_key;
        $sizeBytes = $file->size_bytes;

        DB::transaction(function () use ($user, $file, $sizeBytes): void {
            $file->delete();
            $this->quota->decrementUsage($user, $sizeBytes);
        });

        if ($disk->exists($objectKey)) {
            $disk->delete($objectKey);
        }

        if ($thumbKey !== null && $disk->exists($thumbKey)) {
            $disk->delete($thumbKey);
        }
    }

    public function purgeExpiredTrash(): int
    {
        $expired = DriveFile::query()
            ->with('user')
            ->whereNotNull('deleted_at')
            ->where('purge_after_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expired as $file) {
            $this->purgeFile($file->user, $file);
            $count++;
        }

        return $count;
    }

    public function uniqueFileName(
        User $user,
        Space $space,
        ?string $folderId,
        string $desiredName,
        ?string $ignoreFileId = null,
    ): string {
        $base = pathinfo($desiredName, PATHINFO_FILENAME);
        $ext = pathinfo($desiredName, PATHINFO_EXTENSION);
        $candidate = $desiredName;
        $counter = 2;

        while (true) {
            $query = DriveFile::query()
                ->where('user_id', $user->id)
                ->where('space', $space)
                ->where('folder_id', $folderId)
                ->where('name', $candidate)
                ->whereNull('deleted_at');

            if ($ignoreFileId !== null) {
                $query->where('id', '!=', $ignoreFileId);
            }

            if (! $query->exists()) {
                return $candidate;
            }

            $candidate = $ext !== '' ? "{$base} ({$counter}).{$ext}" : "{$base} ({$counter})";
            $counter++;
        }
    }

    public function generateThumbnail(string $objectKey, Space $space): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if (! $disk->exists($objectKey)) {
            return null;
        }

        $raw = $disk->get($objectKey);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $maxDim = 320;
        $scale = min(1.0, $maxDim / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

        ob_start();
        imagejpeg($dst, null, 82);
        $jpegBytes = ob_get_clean();

        imagedestroy($dst);
        imagedestroy($src);

        if (! is_string($jpegBytes) || $jpegBytes === '') {
            return null;
        }

        $prefix = $space === Space::Private ? 'thumbnails/private' : 'thumbnails/regular';
        $thumbKey = $prefix.'/'.Str::uuid()->toString().'.jpg';
        $disk->put($thumbKey, $jpegBytes);

        return $thumbKey;
    }

    public function generateVideoThumbnail(string $objectKey, Space $space): ?string
    {
        $hasFfmpeg = (bool) Cache::remember('gunydrive:ffmpeg_available', 3600, static function (): bool {
            try {
                return Process::timeout(3)->run(['ffmpeg', '-version'])->successful();
            } catch (\Throwable) {
                return false;
            }
        });

        if (! $hasFfmpeg) {
            return null;
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $fullPath = $disk->path($objectKey);
        if (! is_file($fullPath)) {
            return null;
        }

        $tmpFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gunydrive_vthumb_'.Str::uuid()->toString().'.jpg';

        try {
            $result = Process::timeout(8)->run([
                'ffmpeg',
                '-y',
                '-ss',
                '00:00:01',
                '-i',
                $fullPath,
                '-frames:v',
                '1',
                '-vf',
                'scale=320:-2:force_original_aspect_ratio=decrease',
                '-q:v',
                '5',
                $tmpFile,
            ]);

            if (! $result->successful() || ! is_file($tmpFile) || (int) filesize($tmpFile) === 0) {
                Process::timeout(8)->run([
                    'ffmpeg',
                    '-y',
                    '-ss',
                    '00:00:00',
                    '-i',
                    $fullPath,
                    '-frames:v',
                    '1',
                    '-vf',
                    'scale=320:-2:force_original_aspect_ratio=decrease',
                    '-q:v',
                    '5',
                    $tmpFile,
                ]);
            }

            if (! is_file($tmpFile) || (int) filesize($tmpFile) === 0) {
                return null;
            }

            $jpegBytes = @file_get_contents($tmpFile);
            if (! is_string($jpegBytes) || $jpegBytes === '') {
                return null;
            }

            $prefix = $space === Space::Private ? 'thumbnails/private' : 'thumbnails/regular';
            $thumbKey = $prefix.'/'.Str::uuid()->toString().'.jpg';
            $disk->put($thumbKey, $jpegBytes);

            return $thumbKey;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeFolder(Folder $folder): array
    {
        return [
            'id' => $folder->id,
            'parent_id' => $folder->parent_id,
            'space' => $folder->space->value,
            'name' => $folder->name,
            'files_count' => (int) ($folder->getAttribute('files_count') ?? 0),
            'created_at' => $folder->created_at?->toIso8601String(),
            'updated_at' => $folder->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeFile(DriveFile $file): array
    {
        $preset = $file->resolvedThumbnailPreset();

        return [
            'id' => $file->id,
            'folder_id' => $file->folder_id,
            'space' => $file->space->value,
            'name' => $file->name,
            'size_bytes' => $file->size_bytes,
            'sha256' => $file->sha256,
            'mime_type' => $file->mime_type,
            'category' => $file->category->value,
            'has_preview' => $file->hasPreview(),
            'has_thumbnail' => $file->thumbnail_key !== null || ! $preset->isMedia(),
            'has_custom_thumbnail' => $file->thumbnail_key !== null,
            'thumbnail_preset' => $preset->value,
            'thumbnail_status' => $file->thumbnail_status,
            'thumbnail_mime' => $file->thumbnail_mime,
            'thumbnail_size_bytes' => $file->thumbnail_size_bytes,
            'default_thumbnail_url' => '/thumbnails/defaults/'.$preset->value.'.svg',
            'media_taken_at' => $file->media_taken_at?->toIso8601String(),
            'status' => $file->status,
            'deleted_at' => $file->deleted_at?->toIso8601String(),
            'purge_after_at' => $file->purge_after_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
            'updated_at' => $file->updated_at?->toIso8601String(),
        ];
    }

    private function uniqueFolderName(
        User $user,
        Space $space,
        ?string $parentId,
        string $desiredName,
        ?string $ignoreId = null,
    ): string {
        $candidate = $desiredName;
        $counter = 2;

        while (true) {
            $query = Folder::query()
                ->where('user_id', $user->id)
                ->where('space', $space)
                ->where('parent_id', $parentId)
                ->where('name', $candidate)
                ->whereNull('deleted_at');

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if (! $query->exists()) {
                return $candidate;
            }

            $candidate = "{$desiredName} ({$counter})";
            $counter++;
        }
    }

    private function isDescendant(Folder $candidate, string $ancestorId): bool
    {
        $current = $candidate;
        $depth = 0;

        while ($current->parent_id !== null && $depth < 64) {
            if ($current->parent_id === $ancestorId) {
                return true;
            }
            $next = Folder::query()->find($current->parent_id);
            if ($next === null) {
                break;
            }
            $current = $next;
            $depth++;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectSubtreeFolderIds(Folder $root): array
    {
        $ids = [$root->id];
        $frontier = [$root->id];

        while ($frontier !== []) {
            $children = Folder::query()
                ->whereIn('parent_id', $frontier)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->all();

            $frontier = [];
            foreach ($children as $childId) {
                $id = (string) $childId;
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $frontier[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function buildBreadcrumbs(Folder $folder): array
    {
        $trail = [];
        $current = $folder;
        $depth = 0;

        while ($depth < 64) {
            $trail[] = ['id' => $current->id, 'name' => $current->name];
            if ($current->parent_id === null) {
                break;
            }
            $next = Folder::query()->find($current->parent_id);
            if ($next === null) {
                break;
            }
            $current = $next;
            $depth++;
        }

        return array_reverse($trail);
    }
}
