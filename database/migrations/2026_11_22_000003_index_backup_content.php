<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_files', function (Blueprint $table): void {
            $table->index(['user_id', 'sha256'], 'drive_files_owner_content');
        });
    }

    public function down(): void
    {
        Schema::table('drive_files', function (Blueprint $table): void {
            $table->dropIndex('drive_files_owner_content');
        });
    }
};
