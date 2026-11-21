<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\TrashController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\VaultController;
use App\Http\Controllers\ThumbnailController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.metrics'])->group(function (): void {
    Route::get('/thumbnails/defaults/{preset}.svg', [ThumbnailController::class, 'defaultPreset'])->name('api.v1.thumbnails.default');

    Route::middleware('throttle:8,1')->prefix('auth')->controller(AccountController::class)->group(function (): void {
        Route::post('register', 'register');
        Route::post('verification/resend', 'resend')->middleware('throttle:2,1,verification-resend');
        Route::post('verification/verify', 'verifyCode');
        Route::post('login', 'login');
        Route::post('passkey/login', 'passkeyLogin');
        Route::post('exchange', 'exchange');
        Route::get('poll', 'poll');
    });
    Route::middleware(['api.token:device'])->group(function (): void {
        Route::delete('/auth/session', [AccountController::class, 'logout'])->name('api.v1.logout');
        Route::post('/auth/passkey/enroll', [AccountController::class, 'enrollPasskey'])->name('api.v1.passkey.enroll');
        Route::delete('/auth/passkey', [AccountController::class, 'disablePasskey'])->name('api.v1.passkey.disable');
        Route::get('/me', [MeController::class, 'me'])->name('api.v1.me');
        Route::get('/usage', [MeController::class, 'usage'])->name('api.v1.usage');

        Route::get('/files', [FileController::class, 'index'])->name('api.v1.files.index');
        Route::patch('/files/{fileId}', [FileController::class, 'update'])->name('api.v1.files.update');
        Route::post('/files/{fileId}/copy', [FileController::class, 'copy'])->name('api.v1.files.copy');
        Route::delete('/files/{fileId}', [FileController::class, 'destroy'])->name('api.v1.files.destroy');
        Route::get('/files/{fileId}/content', [FileController::class, 'content'])->name('api.v1.files.content');
        Route::get('/files/{fileId}/thumbnail', [FileController::class, 'thumbnail'])->name('api.v1.files.thumbnail');

        Route::post('/folders', [FolderController::class, 'store'])->name('api.v1.folders.store');
        Route::patch('/folders/{folderId}', [FolderController::class, 'update'])->name('api.v1.folders.update');
        Route::post('/folders/{folderId}/copy', [FolderController::class, 'copy'])->name('api.v1.folders.copy');
        Route::delete('/folders/{folderId}', [FolderController::class, 'destroy'])->name('api.v1.folders.destroy');

        Route::get('/media', [MediaController::class, 'index'])->name('api.v1.media.index');

        Route::post('/uploads', [UploadController::class, 'store'])->name('api.v1.uploads.store');
        Route::get('/uploads/{uploadId}', [UploadController::class, 'show'])->name('api.v1.uploads.show');
        Route::patch('/uploads/{uploadId}', [UploadController::class, 'patch'])->name('api.v1.uploads.patch');
        Route::post('/uploads/{uploadId}/complete', [UploadController::class, 'complete'])->name('api.v1.uploads.complete');
        Route::delete('/uploads/{uploadId}', [UploadController::class, 'destroy'])->name('api.v1.uploads.destroy');

        Route::get('/trash', [TrashController::class, 'index'])->name('api.v1.trash.index');
        Route::delete('/trash', [TrashController::class, 'empty'])->name('api.v1.trash.empty');
        Route::post('/trash/restore-all', [TrashController::class, 'restoreAll'])->name('api.v1.trash.restore-all');
        Route::post('/trash/{fileId}/restore', [TrashController::class, 'restore'])->name('api.v1.trash.restore');
        Route::delete('/trash/{fileId}', [TrashController::class, 'destroy'])->name('api.v1.trash.destroy');

        Route::post('/vault/setup', [VaultController::class, 'setup'])->name('api.v1.vault.setup');
        Route::post('/vault/session', [VaultController::class, 'unlock'])->name('api.v1.vault.unlock');

        Route::middleware(['vault.session'])->group(function (): void {
            Route::delete('/vault/session', [VaultController::class, 'lock'])->name('api.v1.vault.lock');
            Route::get('/vault/files', [VaultController::class, 'files'])->name('api.v1.vault.files');
            Route::post('/vault/folders', [VaultController::class, 'createFolder'])->name('api.v1.vault.folders.store');
            Route::post('/vault/files/{fileId}/move-in', [VaultController::class, 'moveIn'])->name('api.v1.vault.files.move-in');
            Route::delete('/vault/files/{fileId}', [VaultController::class, 'destroyFile'])->name('api.v1.vault.files.destroy');
            Route::get('/vault/trash', [VaultController::class, 'trash'])->name('api.v1.vault.trash.index');
            Route::post('/vault/trash/{fileId}/restore', [VaultController::class, 'restoreTrash'])->name('api.v1.vault.trash.restore');
            Route::delete('/vault/trash/{fileId}', [VaultController::class, 'purgeTrash'])->name('api.v1.vault.trash.destroy');
        });
    });

    Route::middleware(['api.token:integration'])->group(function (): void {
        Route::get('/integrations/{slug}', [IntegrationController::class, 'invoke'])->name('api.v1.integrations.invoke');
    });
});
