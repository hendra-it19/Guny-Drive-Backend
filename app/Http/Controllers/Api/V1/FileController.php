<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\FileCatalogService;
use App\Services\ThumbnailService;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        public FileCatalogService $catalog,
        public VaultService $vault,
        public ThumbnailService $thumbnails,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $folderId = $request->query('folder_id');
        $query = $request->query('q');
        $category = $request->query('category');
        $sort = $request->query('sort', 'date');
        $cursor = $request->query('cursor');
        $perPage = (int) $request->query('per_page', '40');

        $listing = $this->catalog->listDirectory(
            user: $user,
            space: Space::Regular,
            folderId: is_string($folderId) && $folderId !== '' ? $folderId : null,
            query: is_string($query) ? $query : null,
            category: is_string($category) ? $category : null,
            sort: is_string($sort) ? $sort : 'date',
            cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
            perPage: $perPage,
        );

        return response()->json($listing);
    }

    public function update(Request $request, string $fileId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        $updated = $this->catalog->updateFile(
            $user,
            $file,
            array_key_exists('name', $validated) ? (string) $validated['name'] : null,
            array_key_exists('folder_id', $validated) && is_string($validated['folder_id']) ? $validated['folder_id'] : null,
            array_key_exists('folder_id', $validated),
        );

        return response()->json([
            'file' => $this->catalog->serializeFile($updated),
        ]);
    }

    public function copy(Request $request, string $fileId): JsonResponse
    {
        $validated = $request->validate([
            'folder_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        $targetFolderId = isset($validated['folder_id']) && is_string($validated['folder_id'])
            ? $validated['folder_id']
            : null;

        $copied = $this->catalog->copyFile($user, $file, $targetFolderId);

        return response()->json([
            'file' => $this->catalog->serializeFile($copied),
        ], 201);
    }

    public function destroy(Request $request, string $fileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        $trashed = $this->catalog->trashFile($user, $file);

        return response()->json([
            'file' => $this->catalog->serializeFile($trashed),
        ]);
    }

    public function content(Request $request, string $fileId): StreamedResponse|Response
    {
        $file = $this->resolveReadableFile($request, $fileId);
        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));

        if (! $disk->exists($file->object_key)) {
            throw new ApiErrorException('object_missing', 'Objek berkas tidak ditemukan pada disk penyimpanan.', 404);
        }

        $safeDownloadTypes = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript', 'text/javascript'];
        $serveMime = in_array(strtolower($file->mime_type), $safeDownloadTypes, true)
            ? 'application/octet-stream'
            : $file->mime_type;

        $disposition = $request->query('inline') === '1' && preg_match(
            '~^(image/(jpeg|png|gif|webp|avif|heic|heif|bmp)|video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/pdf|application/json|text/(plain|csv|markdown|xml))$~i',
            $serveMime,
        )
            ? 'inline'
            : 'attachment';

        $safeName = str_replace(['"', '\\', "\r", "\n"], '_', $file->name);

        $headers = [
            'Content-Type' => $serveMime,
            'Content-Length' => (string) $file->size_bytes,
            'Content-Disposition' => "{$disposition}; filename=\"{$safeName}\"",
            'X-Content-SHA256' => $file->sha256,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($file->space === Space::Private) {
            $headers['Cache-Control'] = 'no-store, private';
            $headers['Pragma'] = 'no-cache';
        }

        if ($disposition === 'inline' && config('filesystems.disks.'.config('gunydrive.disk').'.driver') === 'local') {
            return response()->file($disk->path($file->object_key), $headers);
        }

        return response()->stream(function () use ($disk, $file): void {
            $stream = $disk->readStream($file->object_key);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    public function thumbnail(Request $request, string $fileId): StreamedResponse|Response
    {
        $file = $this->resolveReadableFile($request, $fileId);
        $resolved = $this->thumbnails->resolveForFile($file);

        if ($resolved['type'] === 'default') {
            $headers = [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => $file->space === Space::Private
                    ? 'no-store, private'
                    : 'public, max-age=604800, immutable',
            ];
            if ($file->space === Space::Private) {
                $headers['Pragma'] = 'no-cache';
            }

            return response($resolved['svg'], 200, $headers);
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $thumbKey = $resolved['key'];

        $headers = [
            'Content-Type' => $resolved['mime'],
            'Content-Length' => (string) $resolved['size_bytes'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $file->space === Space::Private ? 'no-store, private' : 'private, max-age=86400',
        ];

        if ($file->space === Space::Private) {
            $headers['Pragma'] = 'no-cache';
        }

        return response()->stream(function () use ($disk, $thumbKey): void {
            $stream = $disk->readStream($thumbKey);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    private function resolveReadableFile(Request $request, string $fileId): DriveFile
    {
        /** @var User $user */
        $user = $request->user();
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');

        $includeTrashed = $request->query('include_trashed') === '1';

        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->when(! $includeTrashed, fn ($q) => $q->whereNull('deleted_at'))
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
        }

        if ($file->space === Space::Private) {
            $vaultToken = $request->header('X-Vault-Token');
            try {
                $this->vault->validateSession($credential, is_string($vaultToken) ? $vaultToken : null, true);
            } catch (ApiErrorException) {
                // Return generic 404 so hidden private object IDs never leak existence.
                throw new ApiErrorException('file_not_found', 'Berkas tidak ditemukan.', 404);
            }
        }

        return $file;
    }
}
