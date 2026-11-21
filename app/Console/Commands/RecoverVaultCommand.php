<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\VaultService;
use Illuminate\Console\Command;

class RecoverVaultCommand extends Command
{
    protected $signature = 'gunydrive:vault-recover
        {--email= : Surel pemilik}
        {--passphrase= : Frasa sandi baru untuk Folder Privat}';

    protected $description = 'Pemulihan administratif frasa sandi Folder Privat dari konsol lokal host';

    public function handle(VaultService $vault): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Surel pemilik'))));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error('Pemilik dengan surel tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        $passphrase = (string) ($this->option('passphrase') ?: $this->secret('Frasa sandi baru (minimal 6 karakter)'));
        if (strlen($passphrase) < 6) {
            $this->error('Frasa sandi minimal 6 karakter.');

            return self::FAILURE;
        }

        $vault->setPassphrase($user, $passphrase, null, 'vault.admin_recovered');

        $this->info('Frasa sandi Folder Privat diperbarui dan semua sesi privat aktif telah dicabut.');

        return self::SUCCESS;
    }
}
