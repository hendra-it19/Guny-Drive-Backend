<?php

namespace App\Services;

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\ThumbnailPreset;
use App\Models\DriveFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ThumbnailService
{
    public const MAX_DIMENSION = 320;

    public function resolvePreset(string $mimeType, string $filename, FileCategory $category): ThumbnailPreset
    {
        return ThumbnailPreset::fromFile($mimeType, $filename, $category);
    }

    public function defaultUrl(ThumbnailPreset $preset): string
    {
        return '/thumbnails/defaults/'.$preset->value.'.svg';
    }

    /**
     * Generates a resized, optimized thumbnail strictly for Photos & Videos from their original object.
     * Documents (PDF, Word, Excel, PowerPoint, Text, Code, Audio, Archive, Other) NEVER allocate disk space
     * per file because they share the immutable default vector thumbnails.
     *
     * @return array{key: string, mime: string, size_bytes: int}|null
     */
    public function generateOptimizedForObject(string $objectKey, Space $space, FileCategory $category): ?array
    {
        return match ($category) {
            FileCategory::Image => $this->generateOptimizedImageThumbnail($objectKey, $space),
            FileCategory::Video => $this->generateOptimizedVideoThumbnail($objectKey, $space),
            default => null,
        };
    }

    /**
     * Ensures a thumbnail response descriptor exists for any DriveFile:
     * - Photos/Videos: returns custom optimized WebP/JPEG (generating lazily if still pending), falling back to preset SVG.
     * - Documents/Audio/Archive/Other: immediately returns the shared default SVG preset without disk/CPU overhead.
     *
     * @return array{type: 'custom', key: string, preset: ThumbnailPreset, mime: string, size_bytes: int}|array{type: 'default', preset: ThumbnailPreset, mime: string, svg: string}
     */
    public function resolveForFile(DriveFile $file): array
    {
        $preset = $file->resolvedThumbnailPreset();

        if (! $preset->isMedia()) {
            return [
                'type' => 'default',
                'preset' => $preset,
                'mime' => 'image/svg+xml',
                'svg' => $this->renderDefaultSvg($preset),
            ];
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));

        if ($file->thumbnail_key !== null && $disk->exists($file->thumbnail_key)) {
            $mime = $file->thumbnail_mime
                ?? (str_ends_with(strtolower($file->thumbnail_key), '.webp') ? 'image/webp' : 'image/jpeg');
            $size = $file->thumbnail_size_bytes ?? $disk->size($file->thumbnail_key);

            return [
                'type' => 'custom',
                'key' => $file->thumbnail_key,
                'preset' => $preset,
                'mime' => $mime,
                'size_bytes' => $size,
            ];
        }

        if ($file->thumbnail_status !== 'failed' && $file->size_bytes > 0 && $disk->exists($file->object_key)) {
            $generated = $this->generateOptimizedForObject($file->object_key, $file->space, $file->category);
            if ($generated !== null) {
                $file->thumbnail_key = $generated['key'];
                $file->thumbnail_preset = $preset->value;
                $file->thumbnail_mime = $generated['mime'];
                $file->thumbnail_size_bytes = $generated['size_bytes'];
                $file->thumbnail_status = 'generated';
                $file->save();

                return [
                    'type' => 'custom',
                    'key' => $generated['key'],
                    'preset' => $preset,
                    'mime' => $generated['mime'],
                    'size_bytes' => $generated['size_bytes'],
                ];
            }

            $file->thumbnail_preset = $preset->value;
            $file->thumbnail_mime = 'image/svg+xml';
            $file->thumbnail_size_bytes = 0;
            $file->thumbnail_status = 'failed';
            $file->save();
        }

        return [
            'type' => 'default',
            'preset' => $preset,
            'mime' => 'image/svg+xml',
            'svg' => $this->renderDefaultSvg($preset),
        ];
    }

    /**
     * Resizes & compresses a photo into a lightweight WebP (preferred) or progressive JPEG (<= 320px).
     *
     * @return array{key: string, mime: string, size_bytes: int}|null
     */
    public function generateOptimizedImageThumbnail(string $objectKey, Space $space): ?array
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        if (! $disk->exists($objectKey)) {
            return null;
        }

        $raw = $disk->get($objectKey);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return null;
        }

        // Fix portrait/rotated camera EXIF orientation when available
        $src = $this->applyExifOrientation($src, $disk->path($objectKey));

        $width = imagesx($src);
        $height = imagesy($src);

        $scale = min(1.0, self::MAX_DIMENSION / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        // Preserve clean alpha matte for PNG/WebP transparency
        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        }
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);

        $supportsWebp = function_exists('imagewebp') && ((imagetypes() & IMG_WEBP) !== 0);
        $encoded = false;
        $ext = 'jpg';
        $mime = 'image/jpeg';

        if ($supportsWebp) {
            ob_start();
            $ok = @imagewebp($dst, null, 74);
            $bytes = ob_get_clean();
            if ($ok && is_string($bytes) && $bytes !== '') {
                $encoded = $bytes;
                $ext = 'webp';
                $mime = 'image/webp';
            }
        }

        if ($encoded === false) {
            imageinterlace($dst, true);
            ob_start();
            imagejpeg($dst, null, 76);
            $bytes = ob_get_clean();
            if (is_string($bytes) && $bytes !== '') {
                $encoded = $bytes;
                $ext = 'jpg';
                $mime = 'image/jpeg';
            }
        }

        imagedestroy($dst);

        if ($encoded === false) {
            return null;
        }

        $prefix = $space === Space::Private ? 'thumbnails/private' : 'thumbnails/regular';
        $thumbKey = $prefix.'/'.Str::uuid()->toString().'.'.$ext;
        $disk->put($thumbKey, $encoded);

        return [
            'key' => $thumbKey,
            'mime' => $mime,
            'size_bytes' => strlen($encoded),
        ];
    }

    /**
     * Extracts and optimizes a single 320px thumbnail frame from an uploaded video via ffmpeg.
     *
     * @return array{key: string, mime: string, size_bytes: int}|null
     */
    public function generateOptimizedVideoThumbnail(string $objectKey, Space $space): ?array
    {
        $hasFfmpeg = (bool) Cache::remember('gunydrive:ffmpeg_available', 3600, static function (): bool {
            try {
                return Process::timeout(3)->run(['ffmpeg', '-version'])->successful();
            } catch (\Throwable) {
                return false;
            }
        });

        if (! $hasFfmpeg) {
            return null;
        }

        $disk = Storage::disk((string) config('gunydrive.disk', 'local'));
        $fullPath = $disk->path($objectKey);
        if (! is_file($fullPath)) {
            return null;
        }

        $useWebp = extension_loaded('gd') && function_exists('imagewebp');
        $tmpExt = $useWebp ? 'webp' : 'jpg';
        $tmpFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gunydrive_vthumb_'.Str::uuid()->toString().'.'.$tmpExt;

        try {
            $baseArgs = static function (string $seek) use ($fullPath, $tmpFile, $useWebp): array {
                $args = [
                    'ffmpeg',
                    '-y',
                    '-ss',
                    $seek,
                    '-i',
                    $fullPath,
                    '-frames:v',
                    '1',
                    '-vf',
                    'scale='.self::MAX_DIMENSION.':-2:force_original_aspect_ratio=decrease',
                ];
                if ($useWebp) {
                    $args[] = '-quality';
                    $args[] = '74';
                } else {
                    $args[] = '-q:v';
                    $args[] = '5';
                }
                $args[] = $tmpFile;

                return $args;
            };

            $result = Process::timeout(8)->run($baseArgs('00:00:01'));
            if (! $result->successful() || ! is_file($tmpFile) || (int) filesize($tmpFile) === 0) {
                Process::timeout(8)->run($baseArgs('00:00:00'));
            }

            if (! is_file($tmpFile) || (int) filesize($tmpFile) === 0) {
                return null;
            }

            $bytes = @file_get_contents($tmpFile);
            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            $prefix = $space === Space::Private ? 'thumbnails/private' : 'thumbnails/regular';
            $thumbKey = $prefix.'/'.Str::uuid()->toString().'.'.$tmpExt;
            $disk->put($thumbKey, $bytes);

            return [
                'key' => $thumbKey,
                'mime' => $useWebp ? 'image/webp' : 'image/jpeg',
                'size_bytes' => strlen($bytes),
            ];
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Generates a lightweight, crisp, branded default SVG thumbnail for document/non-media types.
     */
    public function renderDefaultSvg(ThumbnailPreset $preset): string
    {
        [$bgStart, $bgEnd, $badgeBg, $accent] = match ($preset) {
            ThumbnailPreset::Pdf => ['#FEE2E2', '#FECACA', '#DC2626', '#EF4444'],
            ThumbnailPreset::Word => ['#DBEAFE', '#BFDBFE', '#2563EB', '#3B82F6'],
            ThumbnailPreset::Excel => ['#DCFCE7', '#BBF7D0', '#16A34A', '#22C55E'],
            ThumbnailPreset::Powerpoint => ['#FFEDD5', '#FED7AA', '#EA580C', '#F97316'],
            ThumbnailPreset::Text => ['#F1F5F9', '#E2E8F0', '#475569', '#64748B'],
            ThumbnailPreset::Code => ['#CFFAFE', '#A5F3FC', '#0891B2', '#06B6D4'],
            ThumbnailPreset::Audio => ['#F3E8FF', '#E9D5FF', '#7C3AED', '#8B5CF6'],
            ThumbnailPreset::Archive => ['#FEF3C7', '#FDE68A', '#D97706', '#F59E0B'],
            ThumbnailPreset::Video => ['#1E293B', '#0F172A', '#6366F1', '#818CF6'],
            ThumbnailPreset::Image => ['#E0E7FF', '#C7D2FE', '#4F46E5', '#6366F1'],
            ThumbnailPreset::File => ['#F1F5F9', '#E2E8F0', '#64748B', '#94A3B8'],
        };

        $badge = $preset->badge();
        $isDark = $preset === ThumbnailPreset::Video;
        $sheetFill = $isDark ? '#1E293B' : '#FFFFFF';
        $sheetStroke = $isDark ? '#334155' : '#E2E8F0';
        $foldFill = $isDark ? '#334155' : $bgEnd;

        $innerGraphic = match ($preset) {
            ThumbnailPreset::Excel => <<<SVG
                <rect x="84" y="92" width="72" height="44" rx="5" fill="none" stroke="{$accent}" stroke-width="3"/>
                <line x1="84" y1="107" x2="156" y2="107" stroke="{$accent}" stroke-width="2.5"/>
                <line x1="84" y1="122" x2="156" y2="122" stroke="{$accent}" stroke-width="2.5"/>
                <line x1="112" y1="92" x2="112" y2="136" stroke="{$accent}" stroke-width="2.5"/>
                <line x1="136" y1="92" x2="136" y2="136" stroke="{$accent}" stroke-width="2.5"/>
            SVG,
            ThumbnailPreset::Powerpoint => <<<SVG
                <rect x="84" y="92" width="72" height="44" rx="6" fill="{$bgStart}" stroke="{$accent}" stroke-width="3"/>
                <circle cx="110" cy="114" r="11" fill="{$accent}" opacity="0.28"/>
                <path d="M110 103 A11 11 0 0 1 121 114 L110 114 Z" fill="{$accent}"/>
                <rect x="128" y="104" width="20" height="5" rx="2.5" fill="{$accent}"/>
                <rect x="128" y="114" width="14" height="5" rx="2.5" fill="{$accent}" opacity="0.65"/>
            SVG,
            ThumbnailPreset::Code => <<<SVG
                <path d="M104 102 L90 114 L104 126" fill="none" stroke="{$accent}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M136 102 L150 114 L136 126" fill="none" stroke="{$accent}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="125" y1="98" x2="115" y2="130" stroke="{$accent}" stroke-width="3.5" stroke-linecap="round"/>
            SVG,
            ThumbnailPreset::Audio => <<<SVG
                <rect x="92" y="108" width="6" height="20" rx="3" fill="{$accent}"/>
                <rect x="104" y="96" width="6" height="44" rx="3" fill="{$accent}"/>
                <rect x="117" y="88" width="6" height="60" rx="3" fill="{$badgeBg}"/>
                <rect x="130" y="98" width="6" height="40" rx="3" fill="{$accent}"/>
                <rect x="142" y="110" width="6" height="16" rx="3" fill="{$accent}"/>
            SVG,
            ThumbnailPreset::Archive => <<<SVG
                <rect x="94" y="90" width="52" height="48" rx="7" fill="{$bgStart}" stroke="{$accent}" stroke-width="3"/>
                <rect x="116" y="90" width="8" height="32" fill="{$accent}" opacity="0.25"/>
                <rect x="114" y="95" width="6" height="4" rx="1" fill="{$badgeBg}"/>
                <rect x="120" y="101" width="6" height="4" rx="1" fill="{$badgeBg}"/>
                <rect x="114" y="107" width="6" height="4" rx="1" fill="{$badgeBg}"/>
                <rect x="115" y="116" width="10" height="10" rx="2.5" fill="{$badgeBg}"/>
            SVG,
            ThumbnailPreset::Video => <<<SVG
                <circle cx="120" cy="112" r="24" fill="{$badgeBg}" opacity="0.9"/>
                <polygon points="113,100 134,112 113,124" fill="#FFFFFF"/>
            SVG,
            ThumbnailPreset::Image => <<<SVG
                <rect x="86" y="92" width="68" height="44" rx="6" fill="{$bgStart}" stroke="{$accent}" stroke-width="3"/>
                <circle cx="104" cy="106" r="5" fill="{$accent}"/>
                <path d="M90 132 L108 114 L122 126 L134 116 L150 132 Z" fill="{$accent}" opacity="0.75"/>
            SVG,
            default => <<<SVG
                <rect x="86" y="94" width="68" height="7" rx="3.5" fill="{$accent}" opacity="0.85"/>
                <rect x="86" y="109" width="56" height="6" rx="3" fill="{$accent}" opacity="0.45"/>
                <rect x="86" y="122" width="62" height="6" rx="3" fill="{$accent}" opacity="0.35"/>
            SVG,
        };

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 240" width="240" height="240">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$bgStart}"/>
      <stop offset="100%" stop-color="{$bgEnd}"/>
    </linearGradient>
  </defs>
  <rect width="240" height="240" fill="url(#bg)"/>
  <path d="M64 36 H144 L176 68 V196 A12 12 0 0 1 164 208 H64 A12 12 0 0 1 52 196 V48 A12 12 0 0 1 64 36 Z" fill="{$sheetFill}" stroke="{$sheetStroke}" stroke-width="2.5"/>
  <path d="M144 36 V60 A8 8 0 0 0 152 68 H176 Z" fill="{$foldFill}"/>
  {$innerGraphic}
  <rect x="68" y="156" width="104" height="32" rx="16" fill="{$badgeBg}"/>
  <text x="120" y="177" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="16" font-weight="800" letter-spacing="0.8" fill="#FFFFFF">{$badge}</text>
</svg>
SVG;
    }

    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data') || ! is_file($path)) {
            return $image;
        }

        $exif = @exif_read_data($path);
        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        $angle = match ((int) $exif['Orientation']) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
