<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\DriveFile;
use App\Models\User;
use App\Models\VaultSession;
use App\Services\FileCatalogService;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VaultController extends Controller
{
    public function __construct(
        public VaultService $vault,
        public FileCatalogService $catalog,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'pin' => ['required', 'string', 'min:4', 'max:32'],
                'pin_confirmation' => ['required', 'string', 'same:pin'],
            ],
            [
                'pin.required' => 'PIN Folder Privat wajib diisi.',
                'pin.min' => 'PIN Folder Privat minimal 4 digit/karakter.',
                'pin.max' => 'PIN Folder Privat maksimal 32 karakter.',
                'pin_confirmation.required' => 'Konfirmasi PIN wajib diisi.',
                'pin_confirmation.same' => 'Konfirmasi PIN tidak cocok dengan PIN yang dimasukkan.',
            ],
        );

        /** @var User $user */
        $user = $request->user();
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');
        $requestId = $request->attributes->get('request_id');
        $pin = (string) $validated['pin'];

        $this->vault->setPassphrase(
            $user,
            $pin,
            is_string($requestId) ? $requestId : null,
            'vault.configured',
        );

        $result = $this->vault->unlock(
            $user,
            $credential,
            $pin,
            is_string($requestId) ? $requestId : null,
        );

        return response()->json([
            'configured' => true,
            'vault_token' => $result['plain_token'],
            'expires_at' => $result['session']->expires_at->toIso8601String(),
            'idle_timeout_seconds' => (int) config('gunydrive.vault_idle_timeout_seconds', 300),
        ], 201)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'passphrase' => ['required', 'string', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var ApiCredential $credential */
        $credential = $request->attributes->get('api_credential');
        $requestId = $request->attributes->get('request_id');

        $result = $this->vault->unlock(
            $user,
            $credential,
            (string) $validated['passphrase'],
            is_string($requestId) ? $requestId : null,
        );

        return response()->json([
            'vault_token' => $result['plain_token'],
            'expires_at' => $result['session']->expires_at->toIso8601String(),
            'idle_timeout_seconds' => (int) config('gunydrive.vault_idle_timeout_seconds', 300),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function lock(Request $request): JsonResponse
    {
        /** @var VaultSession $session */
        $session = $request->attributes->get('vault_session');
        $requestId = $request->attributes->get('request_id');

        $this->vault->lock($session, is_string($requestId) ? $requestId : null);

        return response()->json(['locked' => true])
            ->header('Cache-Control', 'no-store, private');
    }

    public function files(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $folderId = $request->query('folder_id');
        $query = $request->query('q');
        $category = $request->query('category');
        $sort = $request->query('sort', 'date');
        $cursor = $request->query('cursor');
        $perPage = (int) $request->query('per_page', '30');

        $listing = $this->catalog->listDirectory(
            user: $user,
            space: Space::Private,
            folderId: is_string($folderId) && $folderId !== '' ? $folderId : null,
            query: is_string($query) ? $query : null,
            category: is_string($category) ? $category : null,
            sort: is_string($sort) ? $sort : 'date',
            cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
            perPage: $perPage,
        );

        return response()->json($listing);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $parentId = isset($validated['parent_id']) && is_string($validated['parent_id']) ? $validated['parent_id'] : null;

        $folder = $this->catalog->createFolder($user, Space::Private, (string) $validated['name'], $parentId);

        return response()->json([
            'folder' => $this->catalog->serializeFolder($folder),
        ], 201);
    }

    public function moveIn(Request $request, string $fileId): JsonResponse
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
            throw new ApiErrorException('file_not_found', 'Berkas biasa tidak ditemukan.', 404);
        }

        $requestId = $request->attributes->get('request_id');
        $targetFolderId = isset($validated['folder_id']) && is_string($validated['folder_id']) ? $validated['folder_id'] : null;

        $moved = $this->vault->moveFileIntoVault(
            $user,
            $file,
            $targetFolderId,
            is_string($requestId) ? $requestId : null,
        );

        return response()->json([
            'file' => $this->catalog->serializeFile($moved),
        ]);
    }

    public function destroyFile(Request $request, string $fileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Private)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas privat tidak ditemukan.', 404);
        }

        $trashed = $this->catalog->trashFile($user, $file);

        return response()->json([
            'file' => $this->catalog->serializeFile($trashed),
        ]);
    }

    public function trash(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $files = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Private)
            ->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (DriveFile $f): array => $this->catalog->serializeFile($f))
            ->values()
            ->all();

        return response()->json(['files' => $files]);
    }

    public function restoreTrash(Request $request, string $fileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Private)
            ->whereNotNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas privat di Sampah tidak ditemukan.', 404);
        }

        $restored = $this->catalog->restoreFile($user, $file);

        return response()->json([
            'file' => $this->catalog->serializeFile($restored),
        ]);
    }

    public function purgeTrash(Request $request, string $fileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Private)
            ->whereNotNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas privat di Sampah tidak ditemukan.', 404);
        }

        $this->catalog->purgeFile($user, $file);

        return response()->json(['purged' => true]);
    }
}
