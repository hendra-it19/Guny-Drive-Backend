<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Filesystem\Path;

class PublicStorageLink
{
    /** @phpstan-impure */
    public function ready(): bool
    {
        $link = public_path('storage');
        $target = realpath(storage_path('app/public'));

        // Laravel creates a directory junction on Windows. PHP resolves its target
        // with realpath(), even when is_link() reports false.
        return $target !== false && realpath($link) === $target;
    }

    public function create(): void
    {
        if ($this->ready()) {
            return;
        }
        $link = public_path('storage');
        if (is_link($link) || file_exists($link)) {
            throw new RuntimeException('public/storage sudah terisi dengan tujuan berbeda. Minta administrator hosting memeriksanya.');
        }
        $target = storage_path('app/public');
        File::ensureDirectoryExists($target);
        if (PHP_OS_FAMILY === 'Windows') {
            File::link($target, $link);
        } else {
            symlink(Path::makeRelative($target, dirname($link)), $link);
        }
        clearstatcache(true, $link);
        if (! $this->ready()) {
            throw new RuntimeException('Tautan belum aktif. Periksa izin symlink pada hosting.');
        }
    }
}
