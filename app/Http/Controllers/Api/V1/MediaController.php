<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Http\Controllers\Controller;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\FileCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function __construct(public FileCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $type = $request->query('type', 'all');
        $cursor = $request->query('cursor');
        $perPage = min(100, max(1, (int) $request->query('per_page', '48')));

        $baseQuery = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at');

        $query = clone $baseQuery;

        if ($type === 'video') {
            $query->where('category', FileCategory::Video);
        } elseif ($type === 'image') {
            $query->where('category', FileCategory::Image);
        } elseif ($type === 'document') {
            $query->whereNotIn('category', [FileCategory::Image->value, FileCategory::Video->value]);
        } elseif ($type === 'everything') {
            // Include every regular file across all folders and root (photos, videos, documents, audio, archives)
        } else {
            $query->whereIn('category', [FileCategory::Image->value, FileCategory::Video->value]);
        }

        $paginated = $query
            ->orderByRaw('COALESCE(media_taken_at, created_at) DESC')
            ->orderBy('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', is_string($cursor) && $cursor !== '' ? $cursor : null);

        $items = collect($paginated->items())
            ->map(fn (DriveFile $file): array => $this->catalog->serializeFile($file))
            ->values()
            ->all();

        $imageCount = (clone $baseQuery)->where('category', FileCategory::Image)->count();
        $videoCount = (clone $baseQuery)->where('category', FileCategory::Video)->count();
        $everythingCount = (clone $baseQuery)->count();
        $documentCount = max(0, $everythingCount - $imageCount - $videoCount);

        $totalCount = match ($type) {
            'everything' => $everythingCount,
            'image' => $imageCount,
            'video' => $videoCount,
            'document' => $documentCount,
            default => $imageCount + $videoCount,
        };

        return response()->json([
            'items' => $items,
            'total_count' => $totalCount,
            'counts' => [
                'everything' => $everythingCount,
                'image' => $imageCount,
                'video' => $videoCount,
                'document' => $documentCount,
            ],
            'next_cursor' => $paginated->nextCursor()?->encode(),
        ]);
    }
}
