<?php

namespace App\Enums;

enum FileCategory: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Audio = 'audio';
    case Archive = 'archive';
    case Other = 'other';

    public static function fromMimeAndExtension(string $mimeType, string $filename): self
    {
        $mime = strtolower($mimeType);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'bmp', 'avif'], true)) {
            return self::Image;
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'], true)) {
            return self::Video;
        }

        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'm4a', 'flac', 'ogg', 'aac', 'opus'], true)) {
            return self::Audio;
        }

        if (
            in_array($mime, ['application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed', 'application/vnd.rar', 'application/x-bzip2'], true)
            || in_array($ext, ['zip', 'tar', 'gz', 'tgz', '7z', 'rar', 'bz2', 'xz'], true)
        ) {
            return self::Archive;
        }

        if (
            $mime === 'application/pdf'
            || str_starts_with($mime, 'text/')
            || str_contains($mime, 'word')
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'presentation')
            || str_contains($mime, 'officedocument')
            || in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'rtf', 'odt', 'ods'], true)
        ) {
            return self::Document;
        }

        return self::Other;
    }

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Foto',
            self::Video => 'Video',
            self::Document => 'Dokumen',
            self::Audio => 'Audio',
            self::Archive => 'Arsip',
            self::Other => 'Lainnya',
        };
    }
}
