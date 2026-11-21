<?php

use App\Enums\FileCategory;
use App\Enums\ThumbnailPreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_files', function (Blueprint $table): void {
            $table->string('thumbnail_preset', 32)->nullable()->after('thumbnail_key');
            $table->string('thumbnail_mime', 64)->nullable()->after('thumbnail_preset');
            $table->unsignedInteger('thumbnail_size_bytes')->nullable()->after('thumbnail_mime');
            $table->string('thumbnail_status', 20)->default('default')->after('thumbnail_size_bytes');

            $table->index(['thumbnail_status', 'category'], 'idx_drive_files_thumb_status');
        });

        // Backfill existing records with exact preset & status
        DB::table('drive_files')
            ->select(['id', 'name', 'mime_type', 'category', 'thumbnail_key'])
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $cat = FileCategory::tryFrom((string) $row->category) ?? FileCategory::Other;
                    $preset = ThumbnailPreset::fromFile((string) $row->mime_type, (string) $row->name, $cat);

                    $hasCustom = is_string($row->thumbnail_key) && $row->thumbnail_key !== '';
                    $status = $hasCustom
                        ? 'generated'
                        : ($preset->isMedia() ? 'pending' : 'default');
                    $mime = $hasCustom
                        ? (str_ends_with(strtolower((string) $row->thumbnail_key), '.webp') ? 'image/webp' : 'image/jpeg')
                        : 'image/svg+xml';

                    DB::table('drive_files')
                        ->where('id', $row->id)
                        ->update([
                            'thumbnail_preset' => $preset->value,
                            'thumbnail_mime' => $mime,
                            'thumbnail_status' => $status,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('drive_files', function (Blueprint $table): void {
            $table->dropIndex('idx_drive_files_thumb_status');
            $table->dropColumn([
                'thumbnail_preset',
                'thumbnail_mime',
                'thumbnail_size_bytes',
                'thumbnail_status',
            ]);
        });
    }
};
