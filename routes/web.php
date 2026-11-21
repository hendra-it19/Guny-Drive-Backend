<?php

use App\Http\Controllers\Dashboard\ApiAccessController;
use App\Http\Controllers\Dashboard\MonitoringController;
use App\Http\Controllers\Dashboard\OverviewController;
use App\Http\Controllers\Dashboard\StorageController;
use App\Http\Controllers\Dashboard\UserAdminController;
use App\Http\Controllers\MobileBrowserController;
use App\Http\Controllers\ThumbnailController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::inertia('/', 'welcome')->name('home');

Route::get('thumbnails/defaults/{preset}.svg', [ThumbnailController::class, 'defaultPreset'])->name('thumbnails.default');

Route::get('avatars/{user}', function (User $user): BinaryFileResponse {
    abort_unless(
        is_string($user->avatar_path) && $user->avatar_path !== '' && Storage::disk('public')->exists($user->avatar_path),
        404,
    );

    return response()->file(Storage::disk('public')->path($user->avatar_path), [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('users.avatar');

Route::get('email/verify/{id}/{hash}', function (Request $request, string $id, string $hash): RedirectResponse {
    $user = User::find($id);
    abort_unless(
        $user instanceof User && hash_equals(sha1($user->getEmailForVerification()), $hash),
        403,
    );

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect()->to(route('email.verified', absolute: false).'?verified=1');
})->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::inertia('email/verified', 'auth/email-verified')->name('email.verified');

Route::get('mobile/start', [MobileBrowserController::class, 'start'])->middleware('throttle:20,1')->name('mobile.start');
Route::get('mobile/google/callback', [MobileBrowserController::class, 'google']);
Route::get('auth/google/callback', [MobileBrowserController::class, 'google']);
Route::get('google/callback', [MobileBrowserController::class, 'google']);
Route::view('mobile/consent', 'mobile-consent')->middleware('auth')->name('mobile.consent');
Route::post('mobile/consent', [MobileBrowserController::class, 'finish'])->middleware('auth')->name('mobile.finish');

Route::middleware(['auth', 'verified', 'can:manage-server'])->group(function (): void {
    Route::get('dashboard', [OverviewController::class, 'index'])->name('dashboard');

    Route::get('users', [UserAdminController::class, 'index'])->name('users.index');
    Route::put('users/{user}', [UserAdminController::class, 'update'])->name('users.update');
    Route::get('users/{user}/storage', [UserAdminController::class, 'storageDetail'])->name('users.storage');
    Route::post('users/{user}/storage/recalculate', [UserAdminController::class, 'recalculateStorage'])->name('users.storage.recalculate');
    Route::post('users/{user}/trash/purge', [UserAdminController::class, 'purgeUserTrash'])->name('users.trash.purge');
    Route::get('users/{user}/files/{file}/thumbnail', [UserAdminController::class, 'fileThumbnail'])->name('users.files.thumbnail');
    Route::get('users/{user}/files/{file}/content', [UserAdminController::class, 'fileContent'])->name('users.files.content');
    Route::post('users/{user}/files/{file}/restore', [UserAdminController::class, 'restoreUserFile'])->name('users.files.restore');
    Route::delete('users/{user}/files/{file}', [UserAdminController::class, 'deleteUserFile'])->name('users.files.destroy');
    Route::patch('users/{user}/role', [UserAdminController::class, 'toggleAdmin'])->name('users.role.toggle');
    Route::post('users/{user}/verify-email', [UserAdminController::class, 'verifyEmail'])->name('users.verify-email');
    Route::delete('users/{user}/credentials', [UserAdminController::class, 'revoke']);
    Route::delete('users/{user}/credentials/{credential}', [UserAdminController::class, 'revokeCredential']);

    Route::get('dashboard/storage', [StorageController::class, 'index'])->name('storage.index');
    Route::post('storage/link', [StorageController::class, 'link'])->middleware('throttle:6,1');
    Route::put('storage/policy', [StorageController::class, 'updatePolicy'])->name('storage.policy.update');
    Route::post('storage/cleanup', [StorageController::class, 'runCleanup'])->name('storage.cleanup');
    Route::delete('storage/sessions/{session}', [StorageController::class, 'abortSession'])->name('storage.sessions.abort');

    Route::get('api-access', [ApiAccessController::class, 'index'])->name('api-access.index');
    Route::post('api-access/endpoints', [ApiAccessController::class, 'storeEndpoint'])->name('api-access.endpoints.store');
    Route::patch('api-access/endpoints/{endpointId}/toggle', [ApiAccessController::class, 'toggleEndpoint'])->name('api-access.endpoints.toggle');
    Route::post('api-access/credentials', [ApiAccessController::class, 'storeCredential'])->name('api-access.credentials.store');
    Route::delete('api-access/credentials/{credentialId}', [ApiAccessController::class, 'revokeCredential'])->name('api-access.credentials.revoke');

    Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    Route::post('monitoring/prune-logs', [MonitoringController::class, 'pruneLogs'])->name('monitoring.prune-logs');
});

require __DIR__.'/settings.php';
