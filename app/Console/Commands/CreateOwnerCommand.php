<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateOwnerCommand extends Command
{
    protected $signature = 'gunydrive:create-owner
        {--name= : Nama pemilik}
        {--email= : Alamat surel pemilik}
        {--password= : Kata sandi pemilik}';

    protected $description = 'Membuat akun pemilik tunggal untuk instalasi pribadi GunyDrive';

    public function handle(StorageQuotaService $quota): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nama pemilik', 'Pemilik GunyDrive'));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Surel pemilik'))));
        $password = (string) ($this->option('password') ?: $this->secret('Kata sandi pemilik (minimal 8 karakter)'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Alamat surel tidak valid.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Kata sandi minimal 8 karakter.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('Akun dengan surel tersebut sudah terdaftar.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->forceFill(['email_verified_at' => now(), 'is_admin' => true, 'username' => User::availableUsername($email)])->save();

        $quota->forUser($user);

        $this->info("Akun pemilik {$user->email} berhasil dibuat dan terverifikasi.");

        return self::SUCCESS;
    }
}
