<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VaultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VaultPassphraseController extends Controller
{
    public function __construct(public VaultService $vault) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'passphrase' => ['required', 'string', 'min:6', 'max:128', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $this->vault->setPassphrase($user, (string) $validated['passphrase']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Frasa sandi Folder Privat disimpan. Semua sesi privat aktif telah dikunci ulang.',
        ]);

        return back();
    }
}
