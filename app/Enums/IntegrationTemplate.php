<?php

namespace App\Enums;

enum IntegrationTemplate: string
{
    case FolderFiles = 'folder_files';
    case QuotaSummary = 'quota_summary';

    public function label(): string
    {
        return match ($this) {
            self::FolderFiles => 'Daftar berkas folder',
            self::QuotaSummary => 'Ringkasan kuota penyimpanan',
        };
    }

    public function requiredScope(): string
    {
        return match ($this) {
            self::FolderFiles => 'files:read',
            self::QuotaSummary => 'usage:read',
        };
    }
}
