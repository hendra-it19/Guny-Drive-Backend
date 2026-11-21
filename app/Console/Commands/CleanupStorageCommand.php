<?php

namespace App\Console\Commands;

use App\Models\WorkerHeartbeat;
use App\Services\FileCatalogService;
use App\Services\UploadService;
use Illuminate\Console\Command;

class CleanupStorageCommand extends Command
{
    protected $signature = 'gunydrive:cleanup';

    protected $description = 'Membersihkan sesi unggah kedaluwarsa, reservasi kuota, dan berkas Sampah melewati retensi 30 hari';

    public function handle(UploadService $uploads, FileCatalogService $catalog): int
    {
        $expiredUploads = $uploads->cleanupExpiredSessions();
        $purgedTrash = $catalog->purgeExpiredTrash();

        $summary = "Sesi kedaluwarsa: {$expiredUploads}, Sampah dibersihkan: {$purgedTrash}";

        WorkerHeartbeat::query()->updateOrCreate(
            ['job_name' => 'gunydrive:cleanup'],
            [
                'last_ran_at' => now(),
                'status' => 'ok',
                'summary' => $summary,
            ],
        );

        $this->info($summary);

        return self::SUCCESS;
    }
}
