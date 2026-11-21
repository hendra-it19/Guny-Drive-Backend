<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique();
            $table->string('google_id')->nullable()->unique();
            $table->boolean('is_admin')->default(false);
        });
        DB::table('users')->orderBy('id')->each(function ($user): void {
            $base = Str::slug(Str::before($user->email, '@')) ?: 'user';
            $username = $base;
            while (DB::table('users')->where('username', $username)->exists()) {
                $username = $base.'-'.Str::lower(Str::random(6));
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropUnique(['google_id']);
            $table->dropColumn(['username', 'google_id', 'is_admin']);
        });
    }
};
