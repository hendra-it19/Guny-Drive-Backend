<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\FileCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    public function __construct(public FileCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $files = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')
            ->get()
            ->map(fn (DriveFile $f): array => $this->catalog->serializeFile($f))
            ->values()
            ->all();

        return response()->json(['files' => $files]);
    }

    public function restore(Request $request, string $fileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNotNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas di Sampah tidak ditemukan.', 404);
        }

        $restored = $this->catalog->restoreFile($user, $file);

        return response()->json([
            'file' => $this->catalog->serializeFile($restored),
        ]);
    }

    public function destroy(Request $request, string $fileId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = DriveFile::query()
            ->whereKey($fileId)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNotNull('deleted_at')
            ->first();

        if ($file === null) {
            throw new ApiErrorException('file_not_found', 'Berkas di Sampah tidak ditemukan.', 404);
        }

        $this->catalog->purgeFile($user, $file);

        return response()->json(['purged' => true]);
    }

    public function empty(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $trashed = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNotNull('deleted_at')
            ->get();

        $count = 0;
        foreach ($trashed as $file) {
            $this->catalog->purgeFile($user, $file);
            $count++;
        }

        return response()->json(['purged_count' => $count]);
    }

    public function restoreAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $trashed = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNotNull('deleted_at')
            ->get();

        $count = 0;
        foreach ($trashed as $file) {
            $this->catalog->restoreFile($user, $file);
            $count++;
        }

        return response()->json(['restored_count' => $count]);
    }
}
