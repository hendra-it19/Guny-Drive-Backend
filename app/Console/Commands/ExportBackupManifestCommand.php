<?php

namespace App\Console\Commands;

use App\Models\DriveFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportBackupManifestCommand extends Command
{
    protected $signature = 'gunydrive:backup-manifest {--output= : Jalur berkas JSON keluaran}';

    protected $description = 'Menghasilkan manifest checksum objek privat untuk verifikasi backup dan pemulihan server';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $items = [];
        $missing = 0;

        DriveFile::query()->orderBy('created_at')->chunk(200, function ($files) use ($disk, &$items, &$missing): void {
            /** @var DriveFile $file */
            foreach ($files as $file) {
                $exists = $disk->exists($file->object_key);
                if (! $exists) {
                    $missing++;
                }

                $items[] = [
                    'id' => $file->id,
                    'space' => $file->space->value,
                    'object_key' => $file->object_key,
                    'size_bytes' => $file->size_bytes,
                    'sha256' => $file->sha256,
                    'deleted_at' => $file->deleted_at?->toIso8601String(),
                    'object_present' => $exists,
                ];
            }
        });

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'disk' => (string) config('gunydrive.disk', 'local'),
            'total_files' => count($items),
            'missing_objects' => $missing,
            'files' => $items,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, (string) $json);
            $this->info("Manifest ditulis ke {$output} ({$payload['total_files']} berkas, {$missing} hilang).");
        } else {
            $this->line((string) $json);
        }

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
