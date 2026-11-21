<?php

namespace App\Enums;

enum ThumbnailPreset: string
{
    case Pdf = 'pdf';
    case Word = 'word';
    case Excel = 'excel';
    case Powerpoint = 'powerpoint';
    case Text = 'text';
    case Code = 'code';
    case Audio = 'audio';
    case Archive = 'archive';
    case Video = 'video';
    case Image = 'image';
    case File = 'file';

    public static function fromFile(string $mimeType, string $filename, FileCategory $category): self
    {
        $mime = strtolower(trim($mimeType));
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($category === FileCategory::Image || str_starts_with($mime, 'image/')) {
            return self::Image;
        }

        if ($category === FileCategory::Video || str_starts_with($mime, 'video/')) {
            return self::Video;
        }

        if ($category === FileCategory::Audio || str_starts_with($mime, 'audio/')) {
            return self::Audio;
        }

        if ($category === FileCategory::Archive || in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'], true)) {
            return self::Archive;
        }

        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return self::Pdf;
        }

        if (
            in_array($ext, ['xls', 'xlsx', 'xlsm', 'xlsb', 'ods', 'csv', 'tsv'], true)
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || $mime === 'text/csv'
        ) {
            return self::Excel;
        }

        if (
            in_array($ext, ['doc', 'docx', 'docm', 'odt', 'rtf'], true)
            || str_contains($mime, 'wordprocessingml')
            || str_contains($mime, 'msword')
            || str_contains($mime, 'opendocument.text')
        ) {
            return self::Word;
        }

        if (
            in_array($ext, ['ppt', 'pptx', 'pptm', 'odp', 'key'], true)
            || str_contains($mime, 'presentation')
            || str_contains($mime, 'powerpoint')
        ) {
            return self::Powerpoint;
        }

        if (
            in_array($ext, ['json', 'xml', 'yaml', 'yml', 'js', 'ts', 'tsx', 'jsx', 'php', 'py', 'java', 'kt', 'swift', 'sql', 'html', 'htm', 'css', 'sh', 'ini', 'env'], true)
            || in_array($mime, ['application/json', 'application/xml', 'text/xml', 'text/javascript', 'application/javascript'], true)
        ) {
            return self::Code;
        }

        if ($ext === 'txt' || $ext === 'md' || $ext === 'markdown' || $ext === 'log' || str_starts_with($mime, 'text/')) {
            return self::Text;
        }

        return self::File;
    }

    public function isMedia(): bool
    {
        return $this === self::Image || $this === self::Video;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Word => 'WORD',
            self::Excel => 'EXCEL',
            self::Powerpoint => 'PPT',
            self::Text => 'TEXT',
            self::Code => 'CODE',
            self::Audio => 'AUDIO',
            self::Archive => 'ZIP',
            self::Video => 'VIDEO',
            self::Image => 'FOTO',
            self::File => 'FILE',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'Dokumen PDF',
            self::Word => 'Dokumen Word',
            self::Excel => 'Spreadsheet Excel',
            self::Powerpoint => 'Presentasi PowerPoint',
            self::Text => 'Dokumen Teks',
            self::Code => 'Kode & Data',
            self::Audio => 'Berkas Audio',
            self::Archive => 'Arsip Kompresi',
            self::Video => 'Video',
            self::Image => 'Foto / Gambar',
            self::File => 'Berkas',
        };
    }
}
