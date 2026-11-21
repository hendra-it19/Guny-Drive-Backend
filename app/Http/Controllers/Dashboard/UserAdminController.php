<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\Device;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\StoragePolicy;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\AuditService;
use App\Services\FileCatalogService;
use App\Services\StorageQuotaService;
use App\Services\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $search = mb_substr(trim($request->string('q')->toString()), 0, 120);
        $filter = is_string($request->query('filter')) ? $request->query('filter') : 'all';
        $sort = is_string($request->query('sort')) ? $request->query('sort') : 'storage_desc';

        $defaultQuota = (int) config('gunydrive.default_quota_bytes');
        $defaultMaxFile = (int) config('gunydrive.default_max_file_bytes');

        $query = User::query()
            ->leftJoin('storage_policies', 'storage_policies.user_id', '=', 'users.id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.username',
                'users.is_admin',
                'users.email_verified_at',
                'users.created_at',
                DB::raw('COALESCE(storage_policies.used_bytes, 0) as sort_used_bytes'),
            ])
            ->with([
                'storagePolicy',
                'apiCredentials' => fn ($q) => $q
                    ->select(['id', 'user_id', 'label', 'client_type', 'last_used_at'])
                    ->whereNull('revoked_at')
                    ->where(fn ($sq) => $sq->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->latest('created_at'),
            ])
            ->when($search !== '', fn ($q) => $q->whereAny(['users.name', 'users.email', 'users.username'], 'like', '%'.$search.'%'))
            ->when($filter === 'admin', fn ($q) => $q->where('users.is_admin', true))
            ->when($filter === 'unverified', fn ($q) => $q->whereNull('users.email_verified_at'))
            ->when($filter === 'near_quota', fn ($q) => $q->whereRaw('COALESCE(storage_policies.used_bytes, 0) * 100 >= COALESCE(storage_policies.quota_bytes, ?) * 80', [$defaultQuota]));

        match ($sort) {
            'name_asc' => $query->orderBy('users.name'),
            'newest' => $query->orderByDesc('users.created_at'),
            default => $query->orderByDesc('sort_used_bytes')->orderBy('users.id'),
        };

        $paginated = $query->paginate(20)->withQueryString();
        $userIds = collect($paginated->items())->pluck('id')->all();

        // Batched per-user storage breakdown (ZERO N+1 queries)
        $regularCategoryRows = DriveFile::query()
            ->whereIn('user_id', $userIds)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->selectRaw('user_id, category, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('user_id', 'category')
            ->get()
            ->groupBy('user_id');

        $privateRows = DriveFile::query()
            ->whereIn('user_id', $userIds)
            ->where('space', Space::Private)
            ->whereNull('deleted_at')
            ->selectRaw('user_id, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $trashRows = DriveFile::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('deleted_at')
            ->selectRaw('user_id, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $folderCounts = Folder::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('deleted_at')
            ->selectRaw('user_id, COUNT(*) as folder_count')
            ->groupBy('user_id')
            ->pluck('folder_count', 'user_id');

        $deviceStats = Device::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->selectRaw('user_id, COUNT(*) as device_count, MAX(last_backup_at) as last_backup_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $paginated->through(function (User $u) use (
            $regularCategoryRows,
            $privateRows,
            $trashRows,
            $folderCounts,
            $deviceStats,
        ): array {
            $catMap = collect($regularCategoryRows->get($u->id, []))
                ->keyBy(fn (DriveFile $row): string => $row->category->value);

            $categories = [];
            $regularBytes = 0;
            $regularCount = 0;
            foreach (FileCategory::cases() as $cat) {
                /** @var object{file_count?: int|string, total_bytes?: int|string}|null $m */
                $m = $catMap->get($cat->value);
                $bytes = (int) ($m->total_bytes ?? 0);
                $cnt = (int) ($m->file_count ?? 0);
                $regularBytes += $bytes;
                $regularCount += $cnt;
                $categories[] = [
                    'category' => $cat->value,
                    'label' => $cat->label(),
                    'bytes' => $bytes,
                    'count' => $cnt,
                ];
            }

            /** @var object{file_count?: int|string, total_bytes?: int|string}|null $priv */
            $priv = $privateRows->get($u->id);
            /** @var object{file_count?: int|string, total_bytes?: int|string}|null $tr */
            $tr = $trashRows->get($u->id);
            /** @var object{device_count?: int|string, last_backup_at?: string|null}|null $dev */
            $dev = $deviceStats->get($u->id);

            $privateBytes = (int) ($priv->total_bytes ?? 0);
            $privateCount = (int) ($priv->file_count ?? 0);
            $trashBytes = (int) ($tr->total_bytes ?? 0);
            $trashCount = (int) ($tr->file_count ?? 0);

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'username' => $u->username,
                'is_admin' => $u->is_admin,
                'email_verified_at' => $u->email_verified_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'storage_policy' => $u->storagePolicy ? [
                    'quota_bytes' => $u->storagePolicy->quota_bytes,
                    'max_file_bytes' => $u->storagePolicy->max_file_bytes,
                    'used_bytes' => $u->storagePolicy->used_bytes,
                    'reserved_bytes' => $u->storagePolicy->reserved_bytes,
                ] : null,
                'storage_detail' => [
                    'actual_bytes' => $regularBytes + $privateBytes + $trashBytes,
                    'regular_bytes' => $regularBytes,
                    'total_files' => $regularCount + $privateCount,
                    'total_folders' => (int) ($folderCounts->get($u->id) ?? 0),
                    'private_bytes' => $privateBytes,
                    'private_count' => $privateCount,
                    'trash_bytes' => $trashBytes,
                    'trash_count' => $trashCount,
                    'devices_count' => (int) ($dev->device_count ?? 0),
                    'last_backup_at' => is_string($dev->last_backup_at ?? null) ? $dev->last_backup_at : null,
                    'categories' => $categories,
                ],
                'api_credentials' => $u->apiCredentials->map(fn (ApiCredential $c): array => [
                    'id' => $c->id,
                    'label' => $c->label,
                    'client_type' => $c->client_type->value,
                    'last_used_at' => $c->last_used_at?->toIso8601String(),
                ])->values()->all(),
            ];
        });

        // Global summary bar for Users & Storage header
        $totalUsers = User::query()->count();
        $totalUsedBytes = (int) DriveFile::query()->sum('size_bytes');
        $policiesCount = StoragePolicy::query()->count();
        $totalQuotaBytes = (int) StoragePolicy::query()->sum('quota_bytes')
            + max(0, $totalUsers - $policiesCount) * $defaultQuota;
        $totalActiveFiles = (int) DriveFile::query()->whereNull('deleted_at')->count();
        $nearQuotaCount = StoragePolicy::query()
            ->where('quota_bytes', '>', 0)
            ->whereRaw('(used_bytes * 100) >= (quota_bytes * 80)')
            ->count();
        $activeTokensCount = ApiCredential::query()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return Inertia::render('users', [
            'users' => $paginated,
            'search' => $search,
            'filter' => $filter,
            'sort' => $sort,
            'summary' => [
                'total_users' => $totalUsers,
                'total_used_bytes' => $totalUsedBytes,
                'total_quota_bytes' => $totalQuotaBytes,
                'total_files' => $totalActiveFiles,
                'near_quota_count' => $nearQuotaCount,
                'active_tokens_count' => $activeTokensCount,
            ],
            'defaults' => [
                'quota_bytes' => $defaultQuota,
                'max_file_bytes' => $defaultMaxFile,
            ],
        ]);
    }

    public function storageDetail(Request $request, User $user, StorageQuotaService $quota): Response|JsonResponse
    {
        $summary = $quota->usageSummary($user);
        $fileQuery = mb_substr(trim($request->string('q')->toString()), 0, 120);
        $category = trim($request->string('category')->toString());
        $sort = is_string($request->query('sort')) ? $request->query('sort') : 'newest';
        $folderId = is_string($request->query('folder_id')) && trim($request->query('folder_id')) !== ''
            ? trim($request->query('folder_id'))
            : null;

        // Backward-compatible status param + rich explorer view_mode ('explorer' | 'all' | 'trash')
        $statusParam = is_string($request->query('status')) ? $request->query('status') : '';
        $viewModeRaw = is_string($request->query('view_mode'))
            ? $request->query('view_mode')
            : ($statusParam === 'trashed' ? 'trash' : ($request->wantsJson() && ! $request->header('X-Inertia') ? 'all' : 'explorer'));
        $viewMode = in_array($viewModeRaw, ['explorer', 'all', 'trash'], true) ? $viewModeRaw : 'explorer';

        $perPageRaw = is_string($request->query('per_page')) && ctype_digit($request->query('per_page'))
            ? (int) $request->query('per_page')
            : 24;
        $perPage = in_array($perPageRaw, [24, 48, 96], true) ? $perPageRaw : 24;

        // Build complete regular folder tree for this user in 2 batched queries (ZERO N+1, NEVER includes Space::Private)
        $allRegularFolders = Folder::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'created_at', 'updated_at']);

        $folderStatsRows = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->whereNotNull('folder_id')
            ->selectRaw('folder_id, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('folder_id')
            ->get()
            ->keyBy('folder_id');

        $folderMap = $allRegularFolders->keyBy('id');
        $childCountMap = [];
        foreach ($allRegularFolders as $f) {
            if ($f->parent_id !== null) {
                $childCountMap[$f->parent_id] = ($childCountMap[$f->parent_id] ?? 0) + 1;
            }
        }

        // Validate requested folder_id belongs to this user's regular space
        $currentFolder = $folderId !== null ? $folderMap->get($folderId) : null;
        if ($folderId !== null && $currentFolder === null) {
            $folderId = null;
        }

        // Build breadcrumbs from root to currentFolder
        $breadcrumbs = [];
        if ($currentFolder !== null) {
            $cursor = $currentFolder;
            $depth = 0;
            while ($cursor !== null && $depth < 64) {
                $breadcrumbs[] = ['id' => $cursor->id, 'name' => $cursor->name];
                $cursor = $cursor->parent_id !== null ? $folderMap->get($cursor->parent_id) : null;
                $depth++;
            }
            $breadcrumbs = array_reverse($breadcrumbs);
        }

        // Helper to resolve readable path string for any folder_id
        $resolvePath = static function (?string $fid) use ($folderMap): string {
            if ($fid === null) {
                return 'Root';
            }
            $parts = [];
            $cur = $folderMap->get($fid);
            $guard = 0;
            while ($cur !== null && $guard < 32) {
                $parts[] = $cur->name;
                $cur = $cur->parent_id !== null ? $folderMap->get($cur->parent_id) : null;
                $guard++;
            }

            return $parts === [] ? 'Root' : implode(' / ', array_reverse($parts));
        };

        $folderTree = $allRegularFolders->map(function (Folder $f) use ($folderStatsRows, $childCountMap): array {
            /** @var object{file_count?: int|string, total_bytes?: int|string}|null $st */
            $st = $folderStatsRows->get($f->id);

            return [
                'id' => $f->id,
                'parent_id' => $f->parent_id,
                'name' => $f->name,
                'files_count' => (int) ($st->file_count ?? 0),
                'subfolders_count' => $childCountMap[$f->id] ?? 0,
                'total_bytes' => (int) ($st->total_bytes ?? 0),
                'updated_at' => $f->updated_at?->toIso8601String(),
            ];
        })->values()->all();

        // Subfolders visible in the current explorer pane
        $visibleFolders = [];
        if ($viewMode === 'explorer') {
            $filtered = collect($folderTree)
                ->filter(function (array $f) use ($fileQuery, $folderId): bool {
                    if ($fileQuery !== '') {
                        return str_contains(mb_strtolower($f['name']), mb_strtolower($fileQuery));
                    }

                    return $f['parent_id'] === $folderId;
                });

            $sorted = match ($sort) {
                'name_desc' => $filtered->sortByDesc('name', SORT_NATURAL | SORT_FLAG_CASE),
                'size_desc' => $filtered->sortByDesc('total_bytes'),
                'size_asc' => $filtered->sortBy('total_bytes'),
                default => $filtered->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
            };

            $visibleFolders = $sorted->values()->all();
        }

        // Inspect ONLY regular files (private vault filenames remain strictly confidential and are NEVER exposed)
        $filesBuilder = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->when(
                $viewMode === 'trash',
                fn ($q) => $q->whereNotNull('deleted_at'),
                fn ($q) => $q->whereNull('deleted_at'),
            )
            ->when(
                $viewMode === 'explorer' && $fileQuery === '' && ($category === '' || $category === 'all'),
                fn ($q) => $folderId === null ? $q->whereNull('folder_id') : $q->where('folder_id', $folderId),
            )
            ->when(
                $viewMode === 'explorer' && ($fileQuery !== '' || ($category !== '' && $category !== 'all')) && $folderId !== null,
                fn ($q) => $q->where('folder_id', $folderId),
            )
            ->when($fileQuery !== '', fn ($q) => $q->where('name', 'like', '%'.$fileQuery.'%'))
            ->when($category !== '' && $category !== 'all', fn ($q) => $q->where('category', $category));

        match ($sort) {
            'oldest' => $filesBuilder->orderBy('created_at')->orderBy('id'),
            'name_asc' => $filesBuilder->orderBy('name')->orderBy('id'),
            'name_desc' => $filesBuilder->orderByDesc('name')->orderBy('id'),
            'size_desc' => $filesBuilder->orderByDesc('size_bytes')->orderByDesc('created_at'),
            'size_asc' => $filesBuilder->orderBy('size_bytes')->orderBy('created_at'),
            default => $filesBuilder->orderByDesc('created_at')->orderBy('id'),
        };

        $mapFile = static function (DriveFile $f) use ($user, $resolvePath): array {
            $preset = $f->resolvedThumbnailPreset();
            $isMedia = $preset->isMedia();

            return [
                'id' => $f->id,
                'folder_id' => $f->folder_id,
                'folder_path' => $resolvePath($f->folder_id),
                'name' => $f->name,
                'category' => $f->category->value,
                'category_label' => $f->category->label(),
                'mime_type' => $f->mime_type,
                'size_bytes' => $f->size_bytes,
                'sha256' => $f->sha256,
                'has_preview' => $f->hasPreview()
                    || $f->category === FileCategory::Audio
                    || (bool) preg_match('~^(text/(plain|csv|markdown|xml)|application/json)$~i', $f->mime_type),
                'has_thumbnail' => true,
                'has_custom_thumbnail' => $f->thumbnail_key !== null,
                'can_thumbnail' => true,
                'thumbnail_preset' => $preset->value,
                'thumbnail_preset_label' => $preset->label(),
                'thumbnail_status' => $f->thumbnail_status,
                'thumbnail_mime' => $f->thumbnail_mime,
                'thumbnail_size_bytes' => $f->thumbnail_size_bytes,
                'thumbnail_url' => $isMedia
                    ? route('users.files.thumbnail', [$user->id, $f->id], false)
                    : '/thumbnails/defaults/'.$preset->value.'.svg',
                'content_url' => route('users.files.content', [$user->id, $f->id], false).'?inline=1',
                'download_url' => route('users.files.content', [$user->id, $f->id], false),
                'media_taken_at' => $f->media_taken_at?->toIso8601String(),
                'deleted_at' => $f->deleted_at?->toIso8601String(),
                'created_at' => $f->created_at?->toIso8601String(),
            ];
        };

        $devices = Device::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_backup_at')
            ->get()
            ->map(fn (Device $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'platform' => $d->platform,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'last_backup_at' => $d->last_backup_at?->toIso8601String(),
                'revoked' => $d->revoked_at !== null,
            ])
            ->values()
            ->all();

        $sessions = UploadSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [UploadStatus::Open, UploadStatus::Failed, UploadStatus::Expired])
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get()
            ->map(fn (UploadSession $s): array => [
                'id' => $s->id,
                'space' => $s->space->value,
                'status' => $s->status->value,
                'total_size_bytes' => $s->total_size_bytes,
                'received_offset' => $s->received_offset,
                'reserved_bytes' => $s->reserved_bytes,
                'failure_reason' => $s->failure_reason,
                'expires_at' => $s->expires_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $userPayload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'is_admin' => $user->is_admin,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];

        // Support direct JSON requests (API/tests) without breaking Inertia navigation
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            $jsonFiles = $filesBuilder->limit(100)->get()->map($mapFile)->values()->all();

            return response()->json([
                'user' => $userPayload,
                'usage' => $summary,
                'folders' => $visibleFolders,
                'folderTree' => $folderTree,
                'breadcrumbs' => $breadcrumbs,
                'files' => $jsonFiles,
                'devices' => $devices,
                'sessions' => $sessions,
            ]);
        }

        $filesPaginator = $filesBuilder->paginate($perPage)->withQueryString();
        $filesPaginated = [
            'data' => collect($filesPaginator->items())->map($mapFile)->values()->all(),
            'current_page' => $filesPaginator->currentPage(),
            'last_page' => $filesPaginator->lastPage(),
            'per_page' => $filesPaginator->perPage(),
            'total' => $filesPaginator->total(),
            'from' => $filesPaginator->firstItem(),
            'to' => $filesPaginator->lastItem(),
        ];

        return Inertia::render('user-storage', [
            'account' => $userPayload,
            'usage' => $summary,
            'folderTree' => $folderTree,
            'folders' => $visibleFolders,
            'breadcrumbs' => $breadcrumbs,
            'currentFolderId' => $folderId,
            'files' => $filesPaginated,
            'devices' => $devices,
            'sessions' => $sessions,
            'filters' => [
                'folder_id' => $folderId ?? '',
                'view_mode' => $viewMode,
                'q' => $fileQuery,
                'category' => $category === '' ? 'all' : $category,
                'sort' => $sort,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function fileThumbnail(User $user, string $file, ThumbnailService $thumbnails): StreamedResponse|HttpResponse
    {
        $record = DriveFile::query()
            ->whereKey($file)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->firstOrFail();

        $resolved = $thumbnails->resolveForFile($record);

        if ($resolved['type'] === 'default') {
            return response($resolved['svg'], 200, [
                'Content-Type' => 'image/svg+xml; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, max-age=604800, immutable',
            ]);
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $thumbKey = $resolved['key'];

        return response()->stream(function () use ($disk, $thumbKey): void {
            $stream = $disk->readStream($thumbKey);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $resolved['mime'],
            'Content-Length' => (string) $resolved['size_bytes'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function fileContent(Request $request, User $user, string $file): StreamedResponse|HttpResponse
    {
        $record = DriveFile::query()
            ->whereKey($file)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->firstOrFail();

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if (! $disk->exists($record->object_key)) {
            abort(404);
        }

        $safeDownloadTypes = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript', 'text/javascript'];
        $serveMime = in_array(strtolower($record->mime_type), $safeDownloadTypes, true)
            ? 'application/octet-stream'
            : $record->mime_type;

        $disposition = $request->query('inline') === '1' && preg_match(
            '~^(image/(jpeg|png|gif|webp|avif|heic|heif|bmp)|video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/pdf|application/json|text/(plain|csv|markdown|xml))$~i',
            $serveMime,
        )
            ? 'inline'
            : 'attachment';

        $safeName = str_replace(['"', '\\', "\r", "\n"], '_', $record->name);
        $headers = [
            'Content-Type' => $serveMime,
            'Content-Disposition' => "{$disposition}; filename=\"{$safeName}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ];

        // Use BinaryFileResponse on local disk so browser <video>/<audio> Range requests (HTTP 206 seeking) work natively
        if (config('filesystems.disks.'.config('gunydrive.disk', 'local').'.driver') === 'local') {
            return response()->file($disk->path($record->object_key), $headers);
        }

        $headers['Content-Length'] = (string) $record->size_bytes;

        return response()->stream(function () use ($disk, $record): void {
            $stream = $disk->readStream($record->object_key);
            if ($stream === null) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    public function restoreUserFile(User $user, string $file, FileCatalogService $catalog): RedirectResponse
    {
        $record = DriveFile::query()
            ->whereKey($file)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->whereNotNull('deleted_at')
            ->firstOrFail();

        $catalog->restoreFile($user, $record);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Berkas \"{$record->name}\" dipulihkan dari Sampah."]);

        return back();
    }

    public function update(Request $request, User $user, StorageQuotaService $quota): RedirectResponse
    {
        $data = $request->validate([
            'quota_mb' => 'required|integer|min:1|max:104857600',
            'max_file_mb' => 'required|integer|min:0|max:1048576',
        ]);
        $quota->updatePolicy($user, $data['quota_mb'] * 1048576, $data['max_file_mb'] * 1048576);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Batas penyimpanan {$user->name} diperbarui."]);

        return back();
    }

    public function recalculateStorage(User $user, StorageQuotaService $quota, AuditService $audit): RedirectResponse
    {
        $actualUsed = (int) DriveFile::query()->where('user_id', $user->id)->sum('size_bytes');
        $actualReserved = (int) UploadSession::query()
            ->where('user_id', $user->id)
            ->where('status', UploadStatus::Open)
            ->where('expires_at', '>', now())
            ->sum('reserved_bytes');

        DB::transaction(function () use ($user, $quota, $actualUsed, $actualReserved): void {
            $policy = $quota->forUser($user);
            /** @var StoragePolicy $locked */
            $locked = StoragePolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $locked->used_bytes = $actualUsed;
            $locked->reserved_bytes = $actualReserved;
            $locked->save();
        });

        $audit->recordEvent($user->id, 'admin.storage_recalculated', 'success', [
            'used_bytes' => $actualUsed,
            'reserved_bytes' => $actualReserved,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Kuota {$user->name} berhasil disinkronkan ulang."]);

        return back();
    }

    public function purgeUserTrash(User $user, FileCatalogService $catalog, AuditService $audit): RedirectResponse
    {
        $trashed = DriveFile::query()
            ->where('user_id', $user->id)
            ->whereNotNull('deleted_at')
            ->get();

        $count = 0;
        foreach ($trashed as $file) {
            $catalog->purgeFile($user, $file);
            $count++;
        }

        $audit->recordEvent($user->id, 'admin.user_trash_purged', 'success', ['purged_count' => $count]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$count} berkas di Sampah milik {$user->name} telah dihapus permanen."]);

        return back();
    }

    public function deleteUserFile(Request $request, User $user, string $file, FileCatalogService $catalog): RedirectResponse
    {
        $record = DriveFile::query()
            ->whereKey($file)
            ->where('user_id', $user->id)
            ->where('space', Space::Regular)
            ->firstOrFail();

        $permanent = $request->boolean('permanent', false) || $record->deleted_at !== null;
        if ($permanent) {
            $catalog->purgeFile($user, $record);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Berkas \"{$record->name}\" dihapus permanen."]);
        } else {
            $catalog->trashFile($user, $record);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Berkas \"{$record->name}\" dipindahkan ke Sampah."]);
        }

        return back();
    }

    public function toggleAdmin(Request $request, User $user, AuditService $audit): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->id === $user->id) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Anda tidak dapat mencabut hak admin akun sendiri.']);

            return back();
        }

        if ($user->is_admin && User::query()->where('is_admin', true)->count() <= 1) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Minimal harus ada satu admin aktif di server.']);

            return back();
        }

        $user->is_admin = ! $user->is_admin;
        $user->save();

        $audit->recordEvent($user->id, 'admin.role_updated', 'success', ['is_admin' => $user->is_admin, 'by' => $actor->id]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->is_admin
                ? "{$user->name} kini memiliki akses Admin."
                : "Hak Admin {$user->name} telah dicabut.",
        ]);

        return back();
    }

    public function verifyEmail(User $user): RedirectResponse
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Email {$user->email} telah diverifikasi."]);

        return back();
    }

    public function revokeCredential(User $user, string $credential, ApiCredentialService $service): RedirectResponse
    {
        $token = $user->apiCredentials()->findOrFail($credential);
        if (! $token->isRevoked()) {
            $service->revoke($token);
        }

        return back();
    }

    public function revoke(User $user, ApiCredentialService $service): RedirectResponse
    {
        $user->apiCredentials()->whereNull('revoked_at')->eachById(fn ($token) => $service->revoke($token));

        return back();
    }
}
