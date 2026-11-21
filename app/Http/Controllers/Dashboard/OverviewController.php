<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\IntegrationEndpoint;
use App\Models\StoragePolicy;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\VaultConfig;
use App\Models\WorkerHeartbeat;
use App\Services\PublicStorageLink;
use App\Services\StorageQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __construct(public StorageQuotaService $quota) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $usage = $this->quota->usageSummary($user);

        // Global system-wide file & storage aggregates across all users
        $categoryTotals = DriveFile::query()
            ->where('space', Space::Regular)
            ->whereNull('deleted_at')
            ->selectRaw('category, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('category')
            ->get()
            ->keyBy(fn (DriveFile $row): string => $row->category->value);

        $categories = [];
        foreach (FileCategory::cases() as $cat) {
            /** @var object{file_count?: int|string, total_bytes?: int|string}|null $match */
            $match = $categoryTotals->get($cat->value);
            $categories[] = [
                'category' => $cat->value,
                'label' => $cat->label(),
                'bytes' => (int) ($match->total_bytes ?? 0),
                'count' => (int) ($match->file_count ?? 0),
            ];
        }

        $privateStats = DriveFile::query()
            ->where('space', Space::Private)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->first();

        $trashStats = DriveFile::query()
            ->whereNotNull('deleted_at')
            ->selectRaw('COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->first();

        $globalUsedBytes = (int) DriveFile::query()->sum('size_bytes');
        $globalTotalFiles = (int) DriveFile::query()->whereNull('deleted_at')->count();
        $globalTotalFolders = (int) Folder::query()->whereNull('deleted_at')->count();
        $globalReservedBytes = (int) StoragePolicy::query()->sum('reserved_bytes');

        $defaultQuota = (int) config('gunydrive.default_quota_bytes');
        $totalUsers = User::query()->count();
        $policiesCount = StoragePolicy::query()->count();
        $globalQuotaBytes = (int) StoragePolicy::query()->sum('quota_bytes')
            + max(0, $totalUsers - $policiesCount) * $defaultQuota;

        $diskFree = $this->quota->physicalFreeBytes();
        $diskTotal = $this->quota->physicalTotalBytes();
        $diskUsed = ($diskTotal !== null && $diskFree !== null) ? max(0, $diskTotal - $diskFree) : null;

        // Global user health counts
        $adminUsers = User::query()->where('is_admin', true)->count();
        $verifiedUsers = User::query()->whereNotNull('email_verified_at')->count();
        $overQuotaUsers = StoragePolicy::query()->whereColumn('used_bytes', '>', 'quota_bytes')->count();
        $nearQuotaUsers = StoragePolicy::query()
            ->where('quota_bytes', '>', 0)
            ->whereRaw('(used_bytes * 100) >= (quota_bytes * 80)')
            ->count();

        // Top storage consumers across all users
        $fileCountsByUser = DriveFile::query()
            ->whereNull('deleted_at')
            ->selectRaw('user_id, COUNT(*) as cnt, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $topUsers = User::query()
            ->with('storagePolicy')
            ->get(['id', 'name', 'email', 'username', 'is_admin'])
            ->map(function (User $u) use ($fileCountsByUser, $defaultQuota): array {
                $policy = $u->storagePolicy;
                /** @var object{cnt?: int|string, total_bytes?: int|string}|null $stats */
                $stats = $fileCountsByUser->get($u->id);
                $usedBytes = max($policy !== null ? $policy->used_bytes : 0, (int) ($stats->total_bytes ?? 0));
                $quotaBytes = $policy !== null ? $policy->quota_bytes : $defaultQuota;

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'username' => $u->username,
                    'is_admin' => $u->is_admin,
                    'used_bytes' => $usedBytes,
                    'quota_bytes' => $quotaBytes,
                    'files_count' => (int) ($stats->cnt ?? 0),
                ];
            })
            ->sortByDesc('used_bytes')
            ->take(8)
            ->values()
            ->all();

        // Global transfer sessions (active & failed/expired across all users)
        $activeUploads = UploadSession::query()
            ->where('status', UploadStatus::Open)
            ->where('expires_at', '>', now())
            ->count();

        $failedUploads = UploadSession::query()
            ->whereIn('status', [UploadStatus::Failed, UploadStatus::Expired])
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        $sessions = UploadSession::query()
            ->with('user:id,name,email')
            ->whereIn('status', [UploadStatus::Open, UploadStatus::Failed, UploadStatus::Expired])
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get()
            ->map(fn (UploadSession $s): array => [
                'id' => $s->id,
                'user_name' => $s->user->name,
                'user_email' => $s->user->email,
                'space' => $s->space->value,
                'status' => $s->status->value,
                'total_size_bytes' => $s->total_size_bytes,
                'received_offset' => $s->received_offset,
                'reserved_bytes' => $s->reserved_bytes,
                'failure_reason' => $s->failure_reason,
                'expires_at' => $s->expires_at->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        // Global devices & API stats
        $devices = Device::query()
            ->with('user:id,name,email')
            ->orderByDesc('last_backup_at')
            ->limit(12)
            ->get()
            ->map(fn (Device $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'platform' => $d->platform,
                'owner_name' => $d->user->name,
                'owner_email' => $d->user->email,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'last_backup_at' => $d->last_backup_at?->toIso8601String(),
                'revoked' => $d->revoked_at !== null,
            ])
            ->values()
            ->all();

        $lastBackupAt = Device::query()
            ->whereNotNull('last_backup_at')
            ->max('last_backup_at');

        $activeDevicesCount = Device::query()->whereNull('revoked_at')->count();
        $activeEndpoints = IntegrationEndpoint::query()->where('is_active', true)->count();
        $activeCredentials = ApiCredential::query()
            ->whereNull('revoked_at')
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        $requests24h = ApiRequestLog::query()->where('created_at', '>=', now()->subDay())->count();
        $errors4xx24h = ApiRequestLog::query()
            ->where('created_at', '>=', now()->subDay())
            ->whereBetween('status_code', [400, 499])
            ->count();
        $errors5xx24h = ApiRequestLog::query()
            ->where('created_at', '>=', now()->subDay())
            ->where('status_code', '>=', 500)
            ->count();

        $heartbeat = WorkerHeartbeat::query()->where('job_name', 'gunydrive:cleanup')->first();

        return Inertia::render('dashboard', [
            'storageLinkReady' => app(PublicStorageLink::class)->ready(),
            'usage' => $usage,
            'globalStorage' => [
                'used_bytes' => $globalUsedBytes,
                'quota_bytes' => $globalQuotaBytes,
                'reserved_bytes' => $globalReservedBytes,
                'total_files' => $globalTotalFiles,
                'total_folders' => $globalTotalFolders,
                'private_bytes' => (int) ($privateStats->total_bytes ?? 0),
                'private_count' => (int) ($privateStats->file_count ?? 0),
                'trash_bytes' => (int) ($trashStats->total_bytes ?? 0),
                'trash_count' => (int) ($trashStats->file_count ?? 0),
                'disk_total_bytes' => $diskTotal,
                'disk_free_bytes' => $diskFree,
                'disk_used_bytes' => $diskUsed,
                'physical_reserve_bytes' => (int) config('gunydrive.physical_reserve_bytes'),
                'categories' => $categories,
            ],
            'userStats' => [
                'total_users' => $totalUsers,
                'admin_users' => $adminUsers,
                'verified_users' => $verifiedUsers,
                'over_quota_users' => $overQuotaUsers,
                'near_quota_users' => $nearQuotaUsers,
            ],
            'topUsers' => $topUsers,
            'sessions' => $sessions,
            'system' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'storage_disk' => (string) config('gunydrive.disk', 'local'),
                'db_driver' => DB::getDriverName(),
                'memory_limit' => (string) ini_get('memory_limit'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            ],
            'transfers' => [
                'active' => $activeUploads,
                'failed_7d' => $failedUploads,
                'last_backup_at' => is_string($lastBackupAt) ? $lastBackupAt : null,
            ],
            'counts' => [
                'devices' => $activeDevicesCount,
                'active_endpoints' => $activeEndpoints,
                'active_credentials' => $activeCredentials,
                'requests_24h' => $requests24h,
                'errors_4xx_24h' => $errors4xx24h,
                'errors_5xx_24h' => $errors5xx24h,
            ],
            'devices' => $devices,
            'vaultConfigured' => VaultConfig::query()->where('user_id', $user->id)->exists(),
            'worker' => $heartbeat !== null ? [
                'last_ran_at' => $heartbeat->last_ran_at->toIso8601String(),
                'status' => $heartbeat->status,
                'summary' => $heartbeat->summary,
            ] : null,
        ]);
    }
}
