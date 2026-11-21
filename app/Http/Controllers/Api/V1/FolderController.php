<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\User;
use App\Services\FileCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function __construct(public FileCatalogService $catalog) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $parentId = isset($validated['parent_id']) && is_string($validated['parent_id']) ? $validated['parent_id'] : null;

        $folder = $this->catalog->createFolder($user, Space::Regular, (string) $validated['name'], $parentId);

        return response()->json([
            'folder' => $this->catalog->serializeFolder($folder),
        ], 201);
    }

    public function update(Request $request, string $folderId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $folder = Folder::query()
            ->whereKey($folderId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($folder === null) {
            throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
        }

        $updated = $this->catalog->updateFolder(
            $user,
            $folder,
            array_key_exists('name', $validated) ? (string) $validated['name'] : null,
            array_key_exists('parent_id', $validated) && is_string($validated['parent_id']) ? $validated['parent_id'] : null,
            array_key_exists('parent_id', $validated),
        );

        return response()->json([
            'folder' => $this->catalog->serializeFolder($updated),
        ]);
    }

    public function copy(Request $request, string $folderId): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $folder = Folder::query()
            ->whereKey($folderId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($folder === null) {
            throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
        }

        $targetParentId = isset($validated['parent_id']) && is_string($validated['parent_id'])
            ? $validated['parent_id']
            : null;

        $copied = $this->catalog->copyFolder($user, $folder, $targetParentId);

        return response()->json([
            'folder' => $this->catalog->serializeFolder($copied),
        ], 201);
    }

    public function destroy(Request $request, string $folderId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $folder = Folder::query()
            ->whereKey($folderId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->first();

        if ($folder === null) {
            throw new ApiErrorException('folder_not_found', 'Folder tidak ditemukan.', 404);
        }

        $this->catalog->deleteFolder($user, $folder);

        return response()->json(['deleted' => true]);
    }
}
