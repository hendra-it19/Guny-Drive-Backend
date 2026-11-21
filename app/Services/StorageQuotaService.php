<?php

namespace App\Services;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Exceptions\ApiErrorException;
use App\Models\DriveFile;
use App\Models\StoragePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageQuotaService
{
    public function __construct(public AuditService $audit) {}

    public function forUser(User $user): StoragePolicy
    {
        return StoragePolicy::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'quota_bytes' => (int) config('gunydrive.default_quota_bytes'),
                'max_file_bytes' => (int) config('gunydrive.default_max_file_bytes'),
                'physical_reserve_bytes' => (int) config('gunydrive.physical_reserve_bytes'),
                'trash_retention_days' => (int) config('gunydrive.trash_retention_days'),
                'used_bytes' => 0,
                'reserved_bytes' => 0,
            ],
        );
    }

    public function assertAndReserve(User $user, int $sizeBytes): StoragePolicy
    {
        return DB::transaction(function () use ($user, $sizeBytes): StoragePolicy {
            $policy = $this->forUser($user);
            /** @var StoragePolicy $locked */
            $locked = StoragePolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();

            if ($locked->max_file_bytes !== 0 && $sizeBytes > $locked->max_file_bytes) {
                throw new ApiErrorException(
                    'file_too_large',
                    'Ukuran berkas melebihi batas maksimum server.',
                    413,
                    ['max_file_bytes' => $locked->max_file_bytes],
                );
            }

            if ($locked->used_bytes + $locked->reserved_bytes + $sizeBytes > $locked->quota_bytes) {
                throw new ApiErrorException(
                    'quota_exceeded',
                    'Penyimpanan penuh. Naikkan kuota atau hapus berkas di Sampah terlebih dahulu.',
                    409,
                    [
                        'quota_bytes' => $locked->quota_bytes,
                        'used_bytes' => $locked->used_bytes,
                        'reserved_bytes' => $locked->reserved_bytes,
                    ],
                );
            }

            $diskFree = $this->physicalFreeBytes();
            if ($diskFree !== null && $sizeBytes > 0 && ($diskFree - $sizeBytes) < $locked->physical_reserve_bytes) {
                throw new ApiErrorException(
                    'disk_space_low',
                    'Ruang disk fisik server hampir habis.',
                    507,
                    ['disk_free_bytes' => $diskFree, 'physical_reserve_bytes' => $locked->physical_reserve_bytes],
                );
            }

            $locked->reserved_bytes += $sizeBytes;
            $locked->save();

            return $locked;
        });
    }

    public function releaseReservation(User $user, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $bytes): void {
            $policy = $this->forUser($user);
            /** @var StoragePolicy $locked */
            $locked = StoragePolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $locked->reserved_bytes = max(0, $locked->reserved_bytes - $bytes);
            $locked->save();
        });
    }

    public function convertReservationToUsage(User $user, int $reservedBytes, int $actualSizeBytes): void
    {
        DB::transaction(function () use ($user, $reservedBytes, $actualSizeBytes): void {
            $policy = $this->forUser($user);
            /** @var StoragePolicy $locked */
            $locked = StoragePolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $locked->reserved_bytes = max(0, $locked->reserved_bytes - $reservedBytes);
            $locked->used_bytes += max(0, $actualSizeBytes);
            $locked->save();
        });
    }

    public function decrementUsage(User $user, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $bytes): void {
            $policy = $this->forUser($user);
            /** @var StoragePolicy $locked */
            $locked = StoragePolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $locked->used_bytes = max(0, $locked->used_bytes - $bytes);
            $locked->save();
        });
    }

    public function updatePolicy(User $user, int $quotaBytes, int $maxFileBytes, ?string $requestId = null): StoragePolicy
    {
        $policy = DB::transaction(function () use ($user, $quotaBytes, $maxFileBytes): StoragePolicy {
            $existing = $this->forUser($user);
            /** @var StoragePolicy $locked */
            $locked = StoragePolicy::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $locked->quota_bytes = $quotaBytes;
            $locked->max_file_bytes = $maxFileBytes;
            $locked->save();

            return $locked;
        });

        $this->audit->recordEvent($user->id, 'storage.policy_updated', 'success', [
            'quota_bytes' => $quotaBytes,
            'max_file_bytes' => $maxFileBytes,
        ], $requestId);

        return $policy;
    }

    /**
     * @return array<string, mixed>
     */
    public function usageSummary(User $user): array
    {
        $policy = $this->forUser($user);

        $categories = [];
        foreach (FileCategory::cases() as $category) {
            $bytes = (int) DriveFile::query()
                ->where('user_id', $user->id)
                ->where('space', Space::Regular)
                ->whereNull('deleted_at')
                ->where('category', $category)
                ->sum('size_bytes');
            $count = DriveFile::query()
                ->where('user_id', $user->id)
                ->where('space', Space::Regular)
                ->whereNull('deleted_at')
                ->where('category', $category)
                ->count();

            $categories[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'bytes' => $bytes,
                'count' => $count,
            ];
        }

        $privateBytes = (int) DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Private)
            ->whereNull('deleted_at')
            ->sum('size_bytes');

        $privateCount = DriveFile::query()
            ->where('user_id', $user->id)
            ->where('space', Space::Private)
            ->whereNull('deleted_at')
            ->count();

        $trashBytes = (int) DriveFile::query()
            ->where('user_id', $user->id)
            ->whereNotNull('deleted_at')
            ->sum('size_bytes');

        $trashCount = DriveFile::query()
            ->where('user_id', $user->id)
            ->whereNotNull('deleted_at')
            ->count();

        $totalFiles = DriveFile::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->count();

        $diskFree = $this->physicalFreeBytes();
        $diskTotal = $this->physicalTotalBytes();

        return [
            'quota_bytes' => $policy->quota_bytes,
            'used_bytes' => $policy->used_bytes,
            'reserved_bytes' => $policy->reserved_bytes,
            'available_bytes' => $policy->availableQuotaBytes(),
            'max_file_bytes' => $policy->max_file_bytes,
            'physical_reserve_bytes' => $policy->physical_reserve_bytes,
            'trash_retention_days' => $policy->trash_retention_days,
            'private_bytes' => $privateBytes,
            'private_count' => $privateCount,
            'trash_bytes' => $trashBytes,
            'trash_count' => $trashCount,
            'total_files' => $totalFiles,
            'disk_free_bytes' => $diskFree,
            'disk_total_bytes' => $diskTotal,
            'over_quota' => $policy->used_bytes > $policy->quota_bytes,
            'categories' => $categories,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public function physicalFreeBytes(): ?int
    {
        $diskName = (string) config('gunydrive.disk', 'local');
        if (config("filesystems.disks.{$diskName}.driver") !== 'local' || ! function_exists('disk_free_space')) {
            return null;
        }

        $root = Storage::disk($diskName)->path('');
        if (! is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        $free = @disk_free_space($root);

        return is_float($free) ? (int) $free : null;
    }

    public function physicalTotalBytes(): ?int
    {
        $diskName = (string) config('gunydrive.disk', 'local');
        if (config("filesystems.disks.{$diskName}.driver") !== 'local' || ! function_exists('disk_total_space')) {
            return null;
        }

        $root = Storage::disk($diskName)->path('');
        if (! is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        $total = @disk_total_space($root);

        return is_float($total) ? (int) $total : null;
    }
}
