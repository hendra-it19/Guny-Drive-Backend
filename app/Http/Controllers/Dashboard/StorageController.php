<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UploadStatus;
use App\Http\Controllers\Controller;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\WorkerHeartbeat;
use App\Services\FileCatalogService;
use App\Services\PublicStorageLink;
use App\Services\StorageQuotaService;
use App\Services\UploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function __construct(
        public StorageQuotaService $quota,
        public UploadService $uploads,
        public FileCatalogService $catalog,
    ) {}

    public function link(PublicStorageLink $link): RedirectResponse
    {
        try {
            $link->create();
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Storage link aktif.']);
        } catch (\Throwable $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Tautan gagal dibuat. Periksa izin symlink dan konflik public/storage di hosting.']);
        }

        return back();
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $usage = $this->quota->usageSummary($user);

        $sessions = UploadSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [UploadStatus::Open, UploadStatus::Failed, UploadStatus::Expired])
            ->orderByDesc('updated_at')
            ->limit(25)
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
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $heartbeat = WorkerHeartbeat::query()->where('job_name', 'gunydrive:cleanup')->first();

        return Inertia::render('storage', [
            'storageLinkReady' => app(PublicStorageLink::class)->ready(),
            'usage' => $usage,
            'sessions' => $sessions,
            'worker' => $heartbeat !== null ? [
                'last_ran_at' => $heartbeat->last_ran_at->toIso8601String(),
                'status' => $heartbeat->status,
                'summary' => $heartbeat->summary,
            ] : null,
        ]);
    }

    public function updatePolicy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quota_gb' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'max_file_mb' => ['required', 'integer', 'min:0', 'max:102400'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $quotaBytes = (int) round(((float) $validated['quota_gb']) * 1024 * 1024 * 1024);
        $maxFileBytes = ((int) $validated['max_file_mb']) * 1024 * 1024;

        $this->quota->updatePolicy($user, $quotaBytes, $maxFileBytes);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Kebijakan kuota dan batas ukuran berkas berhasil diperbarui.',
        ]);

        return back();
    }

    public function runCleanup(Request $request): RedirectResponse
    {
        $expired = $this->uploads->cleanupExpiredSessions();
        $purged = $this->catalog->purgeExpiredTrash();

        $summary = "Sesi kedaluwarsa: {$expired}, Sampah dibersihkan: {$purged}";

        WorkerHeartbeat::query()->updateOrCreate(
            ['job_name' => 'gunydrive:cleanup'],
            [
                'last_ran_at' => now(),
                'status' => 'ok',
                'summary' => $summary,
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pembersihan selesai. '.$summary,
        ]);

        return back();
    }

    public function abortSession(UploadSession $session): RedirectResponse
    {
        $this->uploads->abortSession($session);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Sesi transfer dibatalkan dan reservasi kuota telah dilepas.',
        ]);

        return back();
    }
}
