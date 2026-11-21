<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('gunydrive.admin_email')));
        $password = (string) config('gunydrive.admin_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            throw new RuntimeException('Isi GUNYDRIVE_ADMIN_EMAIL dan GUNYDRIVE_ADMIN_PASSWORD (minimal 12 karakter).');
        }
        $user = User::firstOrNew(['email' => $email]);
        if ($user->exists && ! $user->is_admin && ! Hash::check($password, $user->password)) {
            throw new RuntimeException('Gunakan kata sandi pemilik lama atau gunakan email admin baru.');
        }
        if (! $user->exists) {
            $user->forceFill(['name' => 'Administrator', 'username' => User::availableUsername($email), 'password' => $password]);
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_admin' => true])->save();
    }
}
