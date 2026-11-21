<?php

use App\Enums\FileCategory;
use App\Enums\Space;
use App\Enums\UploadStatus;
use App\Models\ApiRequestLog;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\StoragePolicy;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\UploadService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('admin can inspect per-user storage breakdown and regular files without exposing private vault filenames', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create(['is_admin' => false, 'name' => 'Budi Santoso']);

    $regularFolder = Folder::factory()->create([
        'user_id' => $member->id,
        'space' => Space::Regular,
        'name' => 'Foto Liburan',
    ]);
    $privateFolder = Folder::factory()->create([
        'user_id' => $member->id,
        'space' => Space::Private,
        'name' => 'Brankas Pribadi',
    ]);

    DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => $regularFolder->id,
        'space' => Space::Regular,
        'name' => 'pantai.jpg',
        'category' => FileCategory::Image,
        'mime_type' => 'image/jpeg',
        'size_bytes' => 2_500_000,
    ]);
    DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => null,
        'space' => Space::Regular,
        'name' => 'proposal.pdf',
        'category' => FileCategory::Document,
        'mime_type' => 'application/pdf',
        'size_bytes' => 1_200_000,
    ]);
    DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => $privateFolder->id,
        'space' => Space::Private,
        'name' => 'rahasia-ktp-super-privat.pdf',
        'category' => FileCategory::Document,
        'mime_type' => 'application/pdf',
        'size_bytes' => 800_000,
    ]);
    DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => null,
        'space' => Space::Regular,
        'name' => 'sampah-lama.zip',
        'category' => FileCategory::Archive,
        'mime_type' => 'application/zip',
        'size_bytes' => 500_000,
        'deleted_at' => now()->subDay(),
    ]);

    Device::query()->create([
        'user_id' => $member->id,
        'name' => 'Pixel 9 Pro',
        'platform' => 'android',
        'last_seen_at' => now(),
        'last_backup_at' => now(),
    ]);

    $this->actingAs($admin);

    // Users page renders with batched storage_detail & global summary
    $this->get(route('users.index'))
        ->assertOk();

    // Per-user storage detail inspector returns regular files & aggregate private metrics, NEVER private filenames
    $response = $this->getJson(route('users.storage', $member))
        ->assertOk()
        ->assertJsonPath('user.id', $member->id)
        ->assertJsonPath('usage.private_count', 1)
        ->assertJsonPath('usage.private_bytes', 800_000)
        ->assertJsonPath('usage.trash_count', 1)
        ->assertJsonPath('usage.trash_bytes', 500_000)
        ->assertJsonCount(2, 'files')
        ->assertJsonCount(1, 'devices');

    $filenames = collect($response->json('files'))->pluck('name')->all();
    expect($filenames)->toContain('pantai.jpg', 'proposal.pdf')
        ->not->toContain('rahasia-ktp-super-privat.pdf');
});

test('admin can recalculate drifted storage quota, delete regular files, and purge user trash', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create(['is_admin' => false]);

    $activeFile = DriveFile::factory()->create([
        'user_id' => $member->id,
        'space' => Space::Regular,
        'name' => 'dokumen-penting.pdf',
        'size_bytes' => 4_000_000,
        'object_key' => 'objects/regular/dokumen-penting.pdf',
    ]);
    $trashedFile = DriveFile::factory()->create([
        'user_id' => $member->id,
        'space' => Space::Regular,
        'name' => 'berkas-lama.mp4',
        'size_bytes' => 3_000_000,
        'object_key' => 'objects/regular/berkas-lama.mp4',
        'deleted_at' => now()->subHours(2),
    ]);
    $privateFile = DriveFile::factory()->create([
        'user_id' => $member->id,
        'space' => Space::Private,
        'name' => 'privat-tidak-boleh-dihapus-admin.jpg',
        'size_bytes' => 1_000_000,
    ]);

    Storage::disk('local')->put($activeFile->object_key, 'content-a');
    Storage::disk('local')->put($trashedFile->object_key, 'content-b');

    // Introduce artificial drift in StoragePolicy
    StoragePolicy::query()->updateOrCreate(
        ['user_id' => $member->id],
        [
            'quota_bytes' => 10_000_000_000,
            'max_file_bytes' => 500_000_000,
            'used_bytes' => 99_999_999,
            'reserved_bytes' => 55_555_555,
        ],
    );

    $this->actingAs($admin);

    // 1. Recalculate quota fixes drift to exact 4M + 3M + 1M = 8M and reserved 0
    $this->post(route('users.storage.recalculate', $member))
        ->assertRedirect();

    $policy = StoragePolicy::query()->where('user_id', $member->id)->firstOrFail();
    expect($policy->used_bytes)->toBe(8_000_000)
        ->and($policy->reserved_bytes)->toBe(0);

    // 2. Purge user trash removes trashedFile and frees 3M
    $this->post(route('users.trash.purge', $member))
        ->assertRedirect();

    expect(DriveFile::query()->whereKey($trashedFile->id)->exists())->toBeFalse();
    $policy->refresh();
    expect($policy->used_bytes)->toBe(5_000_000);

    // 3. Admin cannot delete private vault file via regular file delete endpoint
    $this->delete(route('users.files.destroy', [$member, $privateFile->id]))
        ->assertNotFound();

    // 4. Admin can permanently delete a regular file
    $this->delete(route('users.files.destroy', [$member, $activeFile->id]).'?permanent=1')
        ->assertRedirect();

    expect(DriveFile::query()->whereKey($activeFile->id)->exists())->toBeFalse();
    $policy->refresh();
    expect($policy->used_bytes)->toBe(1_000_000);
});

test('admin can manage roles safely, verify email, abort stuck upload sessions, and prune old api logs', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->unverified()->create(['is_admin' => false]);

    $this->actingAs($admin);

    // Verify member email
    $this->post(route('users.verify-email', $member))
        ->assertRedirect();
    expect($member->fresh()?->hasVerifiedEmail())->toBeTrue();

    // Promote member to admin
    $this->patch(route('users.role.toggle', $member))
        ->assertRedirect();
    expect($member->fresh()?->is_admin)->toBeTrue();

    // Admin cannot demote themselves
    $this->patch(route('users.role.toggle', $admin))
        ->assertRedirect();
    expect($admin->fresh()?->is_admin)->toBeTrue();

    // Demote member back to regular user
    $this->patch(route('users.role.toggle', $member))
        ->assertRedirect();
    expect($member->fresh()?->is_admin)->toBeFalse();

    // Create an open upload session for member with reserved bytes, then abort from admin panel
    $credentials = app(ApiCredentialService::class);
    $issued = $credentials->issueDeviceToken($member, 'Ponsel Uji', 'android');
    $uploads = app(UploadService::class);
    $created = $uploads->createOrResumeSession(
        user: $member,
        credential: $issued['credential'],
        idempotencyKey: 'admin-abort-test-1',
        filename: 'video-macet.mp4',
        totalSizeBytes: 5_000_000,
        expectedSha256: hash('sha256', 'dummy'),
        space: Space::Regular,
    );
    /** @var UploadSession $session */
    $session = $created['session'];
    expect($member->storagePolicy()->firstOrFail()->reserved_bytes)->toBe(5_000_000);

    $this->delete(route('storage.sessions.abort', $session))
        ->assertRedirect();

    expect($session->fresh()?->status)->toBe(UploadStatus::Aborted)
        ->and($session->fresh()?->reserved_bytes)->toBe(0)
        ->and($member->storagePolicy()->firstOrFail()->reserved_bytes)->toBe(0);

    // Prune API logs older than 30 days while preserving recent logs
    $oldLog = ApiRequestLog::query()->create([
        'request_id' => 'req-old-1',
        'user_id' => $member->id,
        'method' => 'GET',
        'route_template' => '/api/v1/files',
        'status_code' => 200,
        'duration_ms' => 15,
        'created_at' => now()->subDays(45),
    ]);
    $recentLog = ApiRequestLog::query()->create([
        'request_id' => 'req-new-1',
        'user_id' => $member->id,
        'method' => 'GET',
        'route_template' => '/api/v1/files',
        'status_code' => 200,
        'duration_ms' => 12,
        'created_at' => now()->subHours(2),
    ]);

    $this->post(route('monitoring.prune-logs'), ['days' => 30])
        ->assertRedirect();

    expect(ApiRequestLog::query()->whereKey($oldLog->id)->exists())->toBeFalse()
        ->and(ApiRequestLog::query()->whereKey($recentLog->id)->exists())->toBeTrue();
});

test('monitoring hub filters and paginates api logs and audit events cleanly', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create(['is_admin' => false, 'name' => 'Rina Wijaya']);

    for ($i = 1; $i <= 14; $i++) {
        ApiRequestLog::query()->create([
            'request_id' => "req-get-{$i}",
            'user_id' => $member->id,
            'method' => 'GET',
            'route_template' => '/api/v1/files',
            'status_code' => 200,
            'duration_ms' => 25,
            'created_at' => now()->subMinutes($i),
        ]);
    }
    ApiRequestLog::query()->create([
        'request_id' => 'req-post-err-99',
        'user_id' => $member->id,
        'method' => 'POST',
        'route_template' => '/api/v1/uploads/sessions',
        'status_code' => 422,
        'error_code' => 'quota_exceeded',
        'duration_ms' => 140,
        'created_at' => now()->subMinute(),
    ]);

    AuditEvent::query()->create([
        'user_id' => $member->id,
        'request_id' => 'audit-req-1',
        'action' => 'admin.storage_recalculated',
        'outcome' => 'success',
        'meta' => ['used_bytes' => 1024],
        'created_at' => now()->subMinutes(5),
    ]);
    AuditEvent::query()->create([
        'user_id' => $member->id,
        'request_id' => 'audit-req-2',
        'action' => 'vault.pin_failed',
        'outcome' => 'denied',
        'meta' => ['attempts' => 2],
        'created_at' => now()->subMinutes(2),
    ]);

    $this->actingAs($admin);

    // Default page caps API logs at 10 rows per page (total 15)
    $this->get(route('monitoring.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('monitoring')
            ->where('logs.per_page', 10)
            ->where('logs.total', 15)
            ->has('logs.data', 10)
            ->where('audits.total', 2));

    // Filter API logs by method + search term + status
    $this->get(route('monitoring.index', [
        'method' => 'POST',
        'status' => '4xx',
        'q' => 'quota_exceeded',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('logs.total', 1)
            ->where('logs.data.0.request_id', 'req-post-err-99'));

    // Filter Audit events by outcome & action
    $this->get(route('monitoring.index', [
        'log_view' => 'audit',
        'audit_outcome' => 'denied',
        'audit_q' => 'pin_failed',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('initialLogView', 'audit')
            ->where('audits.total', 1)
            ->where('audits.data.0.action', 'vault.pin_failed'));
});

test('admin storage explorer page browses nested backup folders, serves lightweight thumbnails and on-demand previews, and isolates private space', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create(['is_admin' => false, 'name' => 'Siti Aminah']);

    $backupFolder = Folder::factory()->create([
        'user_id' => $member->id,
        'parent_id' => null,
        'space' => Space::Regular,
        'name' => 'Backup HP',
    ]);
    $cameraFolder = Folder::factory()->create([
        'user_id' => $member->id,
        'parent_id' => $backupFolder->id,
        'space' => Space::Regular,
        'name' => 'Camera',
    ]);
    $privateFolder = Folder::factory()->create([
        'user_id' => $member->id,
        'parent_id' => null,
        'space' => Space::Private,
        'name' => 'Brankas Rahasia',
    ]);

    // Generate real valid 16x16 PNG bytes via GD when available so thumbnail generation is genuinely tested
    $pngBytes = 'fake-image-bytes';
    if (extension_loaded('gd')) {
        $img = imagecreatetruecolor(16, 16);
        if ($img !== false) {
            $color = imagecolorallocate($img, 59, 130, 246);
            if ($color !== false) {
                imagefill($img, 0, 0, $color);
            }
            ob_start();
            imagepng($img);
            $generated = ob_get_clean();
            imagedestroy($img);
            if (is_string($generated) && $generated !== '') {
                $pngBytes = $generated;
            }
        }
    }
    Storage::disk('local')->put('objects/regular/foto.png', $pngBytes);
    Storage::disk('local')->put('objects/regular/catatan.txt', 'Halo dunia dari GunyDrive');
    Storage::disk('local')->put('objects/regular/unsafe.html', '<script>alert(1)</script>');
    Storage::disk('local')->put('objects/private/secret.png', $pngBytes);

    $photo = DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => $cameraFolder->id,
        'space' => Space::Regular,
        'name' => 'liburan.png',
        'object_key' => 'objects/regular/foto.png',
        'category' => FileCategory::Image,
        'mime_type' => 'image/png',
        'size_bytes' => strlen($pngBytes),
    ]);
    $note = DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => null,
        'space' => Space::Regular,
        'name' => 'catatan.txt',
        'object_key' => 'objects/regular/catatan.txt',
        'category' => FileCategory::Document,
        'mime_type' => 'text/plain',
        'size_bytes' => 25,
    ]);
    $unsafeHtml = DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => null,
        'space' => Space::Regular,
        'name' => 'unsafe.html',
        'object_key' => 'objects/regular/unsafe.html',
        'category' => FileCategory::Document,
        'mime_type' => 'text/html',
        'size_bytes' => 25,
    ]);
    $privatePhoto = DriveFile::factory()->create([
        'user_id' => $member->id,
        'folder_id' => $privateFolder->id,
        'space' => Space::Private,
        'name' => 'rahasia.png',
        'object_key' => 'objects/private/secret.png',
        'category' => FileCategory::Image,
        'mime_type' => 'image/png',
        'size_bytes' => strlen($pngBytes),
    ]);

    $this->actingAs($admin);

    // 1. Root explorer renders dedicated Inertia page with Backup HP folder & root files, never exposing private folder
    $this->get(route('users.storage', $member))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user-storage')
            ->has('folderTree', 2)
            ->has('folders', 1)
            ->where('folders.0.name', 'Backup HP')
            ->has('files.data', 2));

    // 2. Navigating inside nested Camera folder builds full breadcrumbs & lists photo with thumbnail_url
    $this->get(route('users.storage', ['user' => $member->id, 'folder_id' => $cameraFolder->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('user-storage')
            ->has('breadcrumbs', 2)
            ->where('breadcrumbs.0.name', 'Backup HP')
            ->where('breadcrumbs.1.name', 'Camera')
            ->has('files.data', 1)
            ->where('files.data.0.name', 'liburan.png')
            ->where('files.data.0.folder_path', 'Backup HP / Camera'));

    // 3. Lightweight thumbnail endpoint generates and returns optimized WebP/JPEG (if GD loaded), default SVG for docs, and blocks Private files with 404
    if (extension_loaded('gd')) {
        $photoThumb = $this->get(route('users.files.thumbnail', [$member, $photo->id]))
            ->assertOk();
        expect($photoThumb->headers->get('Content-Type'))->toBeIn(['image/webp', 'image/jpeg']);
        $freshPhoto = $photo->fresh();
        expect($freshPhoto?->thumbnail_key)->not->toBeNull()
            ->and($freshPhoto?->thumbnail_status)->toBe('generated')
            ->and($freshPhoto?->thumbnail_size_bytes)->toBeGreaterThan(0);
    }

    // Documents receive shared immutable default SVG URL in explorer listing and return SVG on thumbnail endpoint
    $explorerJson = $this->getJson(route('users.storage', $member))->assertOk();
    $noteEntry = collect($explorerJson->json('files'))->firstWhere('id', $note->id);
    expect($noteEntry)->not->toBeNull()
        ->and($noteEntry['thumbnail_preset'])->toBe('text')
        ->and($noteEntry['thumbnail_url'])->toBe('/thumbnails/defaults/text.svg');

    $this->get(route('users.files.thumbnail', [$member, $note->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');

    $this->get(route('users.files.thumbnail', [$member, $privatePhoto->id]))
        ->assertNotFound();

    // 4. On-demand content endpoint previews safe text inline, blocks XSS inline on HTML, and blocks Private files with 404
    $this->get(route('users.files.content', [$member, $note->id]).'?inline=1')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="catatan.txt"');

    $this->get(route('users.files.content', [$member, $unsafeHtml->id]).'?inline=1')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename="unsafe.html"');

    $this->get(route('users.files.content', [$member, $privatePhoto->id]).'?inline=1')
        ->assertNotFound();

    // 5. Trash & Restore flow from Explorer
    $this->delete(route('users.files.destroy', [$member, $note->id]))->assertRedirect();
    expect($note->fresh()?->deleted_at)->not->toBeNull();

    $this->post(route('users.files.restore', [$member, $note->id]))->assertRedirect();
    expect($note->fresh()?->deleted_at)->toBeNull();
});

test('non-admin users are forbidden from all new admin management endpoints', function () {
    $member = User::factory()->create(['is_admin' => false]);
    $other = User::factory()->create(['is_admin' => false]);
    $file = DriveFile::factory()->create(['user_id' => $other->id, 'space' => Space::Regular]);

    $this->actingAs($member);

    $this->getJson(route('users.storage', $other))->assertForbidden();
    $this->get(route('users.storage', $other))->assertForbidden();
    $this->get(route('users.files.thumbnail', [$other, $file->id]))->assertForbidden();
    $this->get(route('users.files.content', [$other, $file->id]))->assertForbidden();
    $this->post(route('users.files.restore', [$other, $file->id]))->assertForbidden();
    $this->post(route('users.storage.recalculate', $other))->assertForbidden();
    $this->post(route('users.trash.purge', $other))->assertForbidden();
    $this->patch(route('users.role.toggle', $other))->assertForbidden();
    $this->post(route('users.verify-email', $other))->assertForbidden();
    $this->post(route('monitoring.prune-logs'))->assertForbidden();
});
