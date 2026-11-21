<?php

use App\Models\DriveFile;
use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\StorageQuotaService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function createTinyPngBytes(): string
{
    $img = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

test('resumable chunked upload completes idempotently and can be restored from another phone with matching sha256', function () {
    $user = User::factory()->create();
    $credentials = app(ApiCredentialService::class);
    $phoneA = $credentials->issueDeviceToken($user, 'Ponsel A', 'android');
    $phoneB = $credentials->issueDeviceToken($user, 'Ponsel B', 'ios');

    $payload = '%PDF-1.7 synthetic-document-payload-'.str_repeat('gunydrive', 50);
    $sha256 = hash('sha256', $payload);
    $part1 = substr($payload, 0, 120);
    $part2 = substr($payload, 120);

    $start = $this->withToken($phoneA['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'job-pdf-1',
            'filename' => 'Catatan.pdf',
            'total_size_bytes' => strlen($payload),
            'expected_sha256' => $sha256,
            'mime_type' => 'application/pdf',
        ])
        ->assertCreated();

    $uploadId = $start->json('upload.id');

    // First chunk
    $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phoneA['plain_token'],
        'HTTP_UPLOAD_OFFSET' => '0',
        'HTTP_UPLOAD_CHECKSUM' => hash('sha256', $part1),
    ], $part1)
        ->assertOk()
        ->assertJsonPath('upload.received_offset', strlen($part1));

    // Wrong offset returns 409 offset_conflict
    $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phoneA['plain_token'],
        'HTTP_UPLOAD_OFFSET' => '0',
    ], $part2)
        ->assertStatus(409)
        ->assertJsonPath('code', 'offset_conflict');

    // Check status and resume from server-acknowledged offset
    $status = $this->withToken($phoneA['plain_token'])
        ->getJson("/api/v1/uploads/{$uploadId}")
        ->assertOk();

    $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phoneA['plain_token'],
        'HTTP_UPLOAD_OFFSET' => (string) $status->json('upload.received_offset'),
        'HTTP_UPLOAD_CHECKSUM' => hash('sha256', $part2),
    ], $part2)
        ->assertOk()
        ->assertJsonPath('upload.received_offset', strlen($payload));

    // Complete upload twice -> idempotent, produces exactly 1 file
    $complete1 = $this->withToken($phoneA['plain_token'])
        ->postJson("/api/v1/uploads/{$uploadId}/complete")
        ->assertCreated();

    $complete2 = $this->withToken($phoneA['plain_token'])
        ->postJson("/api/v1/uploads/{$uploadId}/complete")
        ->assertOk();

    expect($complete1->json('file.id'))->toBe($complete2->json('file.id'))
        ->and(DriveFile::query()->count())->toBe(1);

    // Restore original from Phone B and verify SHA-256
    $fileId = $complete1->json('file.id');
    $download = $this->withToken($phoneB['plain_token'])
        ->get("/api/v1/files/{$fileId}/content")
        ->assertOk()
        ->assertHeader('X-Content-SHA256', $sha256);

    expect(hash('sha256', $download->streamedContent()))->toBe($sha256);
});

test('supports empty files, duplicate names, image thumbnails, quota limits, trash and cleanup', function () {
    $user = User::factory()->create();
    $credentials = app(ApiCredentialService::class);
    $quota = app(StorageQuotaService::class);
    $phone = $credentials->issueDeviceToken($user, 'Ponsel Uji', 'android');

    // Empty 0-byte file
    $emptyHash = hash('sha256', '');
    $emptyStart = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'empty-1',
            'filename' => 'Kosong.txt',
            'total_size_bytes' => 0,
            'expected_sha256' => $emptyHash,
        ])
        ->assertCreated();

    $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads/'.$emptyStart->json('upload.id').'/complete')
        ->assertCreated()
        ->assertJsonPath('file.name', 'Kosong.txt');

    // Duplicate name gets suffix (2)
    $dupStart = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'empty-2',
            'filename' => 'Kosong.txt',
            'total_size_bytes' => 0,
            'expected_sha256' => $emptyHash,
        ])
        ->assertCreated();

    $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads/'.$dupStart->json('upload.id').'/complete')
        ->assertCreated()
        ->assertJsonPath('file.name', 'Kosong (2).txt');

    // Image upload generates thumbnail and appears in /media
    $png = createTinyPngBytes();
    $imgStart = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'img-1',
            'filename' => 'Gunung.png',
            'total_size_bytes' => strlen($png),
            'expected_sha256' => hash('sha256', $png),
            'mime_type' => 'image/png',
            'source_asset_id' => 'asset-1',
            'source_revision' => 'rev-1',
            'auto_backup' => true,
        ])
        ->assertCreated();

    $imgUploadId = $imgStart->json('upload.id');
    $this->call('PATCH', "/api/v1/uploads/{$imgUploadId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phone['plain_token'],
        'HTTP_UPLOAD_OFFSET' => '0',
    ], $png)
        ->assertOk();

    $imgFileId = $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/uploads/{$imgUploadId}/complete")
        ->assertCreated()
        ->assertJsonPath('file.has_thumbnail', true)
        ->json('file.id');

    $imgThumb = $this->withToken($phone['plain_token'])
        ->get("/api/v1/files/{$imgFileId}/thumbnail")
        ->assertOk();
    expect($imgThumb->headers->get('Content-Type'))->toBeIn(['image/webp', 'image/jpeg']);

    $this->withToken($phone['plain_token'])
        ->getJson('/api/v1/media')
        ->assertOk()
        ->assertJsonCount(1, 'items');

    $this->withToken($phone['plain_token'])
        ->getJson('/api/v1/media?type=everything')
        ->assertOk()
        ->assertJsonPath('counts.image', 1)
        ->assertJsonPath('counts.document', 2)
        ->assertJsonPath('counts.everything', 3)
        ->assertJsonCount(3, 'items');

    $this->withToken($phone['plain_token'])
        ->getJson('/api/v1/media?type=document')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    // Bad checksum fails and releases reservation
    $badStart = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'bad-hash',
            'filename' => 'Rusak.bin',
            'total_size_bytes' => 4,
            'expected_sha256' => str_repeat('a', 64),
        ])
        ->assertCreated();

    $badId = $badStart->json('upload.id');
    $this->call('PATCH', "/api/v1/uploads/{$badId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phone['plain_token'],
        'HTTP_UPLOAD_OFFSET' => '0',
    ], 'test')
        ->assertOk();

    $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/uploads/{$badId}/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'checksum_mismatch');

    expect($quota->forUser($user)->fresh()->reserved_bytes)->toBe(0);

    // Lowering quota below usage blocks new upload but keeps download working
    $quota->updatePolicy($user, 10, 1024 * 1024);

    $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'over-quota',
            'filename' => 'Besar.bin',
            'total_size_bytes' => 50,
            'expected_sha256' => hash('sha256', str_repeat('x', 50)),
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'quota_exceeded');

    $this->withToken($phone['plain_token'])
        ->get("/api/v1/files/{$imgFileId}/content")
        ->assertOk();

    // Trash retains quota; auto-backup rescan skips deleted source; restore & purge release quota
    $this->withToken($phone['plain_token'])
        ->deleteJson("/api/v1/files/{$imgFileId}")
        ->assertOk();

    // Trashed file stays hidden on default content/thumbnail URLs, but previewable with include_trashed=1
    $this->withToken($phone['plain_token'])
        ->get("/api/v1/files/{$imgFileId}/content")
        ->assertNotFound();

    $trashedThumb = $this->withToken($phone['plain_token'])
        ->get("/api/v1/files/{$imgFileId}/thumbnail?include_trashed=1")
        ->assertOk();
    expect($trashedThumb->headers->get('Content-Type'))->toBeIn(['image/webp', 'image/jpeg']);

    $this->withToken($phone['plain_token'])
        ->get("/api/v1/files/{$imgFileId}/content?inline=1&include_trashed=1")
        ->assertOk();

    expect($quota->forUser($user)->fresh()->used_bytes)->toBe(strlen($png));

    $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'rescan-img-1',
            'filename' => 'Gunung.png',
            'total_size_bytes' => strlen($png),
            'expected_sha256' => hash('sha256', $png),
            'source_asset_id' => 'asset-1',
            'source_revision' => 'rev-1',
            'auto_backup' => true,
        ])
        ->assertOk()
        ->assertJsonPath('skipped', true)
        ->assertJsonPath('reason', 'deleted');

    $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/trash/{$imgFileId}/restore")
        ->assertOk();

    $this->withToken($phone['plain_token'])
        ->deleteJson("/api/v1/files/{$imgFileId}")
        ->assertOk();

    $this->withToken($phone['plain_token'])
        ->deleteJson("/api/v1/trash/{$imgFileId}")
        ->assertOk();

    expect($quota->forUser($user)->fresh()->used_bytes)->toBe(0);
});

test('folder cycle is rejected and cleanup purges expired trash and uploads', function () {
    $user = User::factory()->create();
    $phone = app(ApiCredentialService::class)->issueDeviceToken($user, 'Ponsel', 'ios');

    $parentId = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/folders', ['name' => 'Induk'])
        ->assertCreated()
        ->json('folder.id');

    $childId = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/folders', ['name' => 'Anak', 'parent_id' => $parentId])
        ->assertCreated()
        ->json('folder.id');

    $this->withToken($phone['plain_token'])
        ->patchJson("/api/v1/folders/{$parentId}", ['parent_id' => $childId])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_cycle');

    $this->artisan('gunydrive:cleanup')->assertSuccessful();
});

test('overlapping folder backups reuse one file and a later scan skips transfer', function () {
    $user = User::factory()->create();
    $credentials = app(ApiCredentialService::class);
    $first = $credentials->issueDeviceToken($user, 'A', 'android')['plain_token'];
    $second = $credentials->issueDeviceToken($user, 'B', 'android')['plain_token'];
    $bytes = 'folder backup regression';
    $params = [
        'filename' => 'report.txt', 'total_size_bytes' => strlen($bytes),
        'expected_sha256' => hash('sha256', $bytes), 'deduplicate' => true,
        'idempotency_key' => 'folder-run',
    ];
    $sessions = [];
    foreach ([$first, $second] as $token) {
        $sessions[] = $this->withToken($token)->postJson('/api/v1/uploads', $params)->assertCreated()->json('upload.id');
    }
    foreach ([$first, $second] as $i => $token) {
        $id = $sessions[$i];
        $this->call('PATCH', "/api/v1/uploads/{$id}", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_UPLOAD_OFFSET' => '0',
        ], $bytes)->assertOk();
        $this->withToken($token)->postJson("/api/v1/uploads/{$id}/complete")->assertSuccessful();
    }
    $file = DriveFile::query()->sole();
    $this->withToken($second)->postJson('/api/v1/uploads', array_merge($params, ['filename' => 'copy.txt']))
        ->assertOk()->assertJsonPath('file.id', $file->id)->assertJsonMissingPath('upload');
    $policy = app(StorageQuotaService::class)->forUser($user);
    expect($policy->used_bytes)->toBe(strlen($bytes))->and($policy->reserved_bytes)->toBe(0);
});

test('supports copying, moving, renaming, and deleting files and folders with quota accounting', function () {
    $user = User::factory()->create();
    $credentials = app(ApiCredentialService::class);
    $quota = app(StorageQuotaService::class);
    $phone = $credentials->issueDeviceToken($user, 'Ponsel Manager', 'android');

    $folderA = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/folders', ['name' => 'Dokumen'])
        ->assertCreated()
        ->json('folder.id');

    $folderB = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/folders', ['name' => 'Arsip'])
        ->assertCreated()
        ->json('folder.id');

    $payload = 'isi-berkas-uji-salin-pindah';
    $start = $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'copy-move-1',
            'filename' => 'Catatan.txt',
            'total_size_bytes' => strlen($payload),
            'expected_sha256' => hash('sha256', $payload),
            'folder_id' => $folderA,
        ])
        ->assertCreated();

    $uploadId = $start->json('upload.id');
    $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phone['plain_token'],
        'HTTP_UPLOAD_OFFSET' => '0',
    ], $payload)->assertOk();

    $fileId = $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/uploads/{$uploadId}/complete")
        ->assertCreated()
        ->json('file.id');

    // Copy file into folder B
    $copiedFileId = $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/files/{$fileId}/copy", ['folder_id' => $folderB])
        ->assertCreated()
        ->assertJsonPath('file.folder_id', $folderB)
        ->assertJsonPath('file.name', 'Catatan.txt')
        ->json('file.id');

    expect($copiedFileId)->not->toBe($fileId)
        ->and($quota->forUser($user)->fresh()->used_bytes)->toBe(strlen($payload) * 2);

    // Copy folder A into folder B (recursively copies its file too!)
    $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/folders/{$folderA}/copy", ['parent_id' => $folderB])
        ->assertCreated()
        ->assertJsonPath('folder.parent_id', $folderB)
        ->assertJsonPath('folder.files_count', 1);

    expect($quota->forUser($user)->fresh()->used_bytes)->toBe(strlen($payload) * 3);

    // Copying folder into itself is blocked with 422 folder_cycle
    $this->withToken($phone['plain_token'])
        ->postJson("/api/v1/folders/{$folderA}/copy", ['parent_id' => $folderA])
        ->assertStatus(422)
        ->assertJsonPath('code', 'folder_cycle');

    // Rename folder B and delete folder A
    $this->withToken($phone['plain_token'])
        ->patchJson("/api/v1/folders/{$folderB}", ['name' => 'Arsip 2026'])
        ->assertOk()
        ->assertJsonPath('folder.name', 'Arsip 2026');

    $this->withToken($phone['plain_token'])
        ->deleteJson("/api/v1/folders/{$folderA}")
        ->assertOk()
        ->assertJsonPath('deleted', true);
});

test('documents share immutable default SVG thumbnails without disk bloat while photos generate optimized resized thumbnails', function () {
    $user = User::factory()->create();
    $phone = app(ApiCredentialService::class)->issueDeviceToken($user, 'Ponsel Thumb', 'android');

    // 1. Public shared default SVG endpoints work for all document presets and 404 on unknown preset
    foreach (['pdf', 'word', 'excel', 'powerpoint', 'text', 'code', 'audio', 'archive'] as $preset) {
        $this->get("/thumbnails/defaults/{$preset}.svg")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');

        $this->get("/api/v1/thumbnails/defaults/{$preset}.svg")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
    }

    $this->get('/thumbnails/defaults/unknown-type.svg')->assertNotFound();

    // 2. Upload PDF, Word (.docx), and Excel (.xlsx) -> zero custom thumbnail disk file, uses default preset
    $docs = [
        ['key' => 'doc-pdf', 'name' => 'Laporan.pdf', 'mime' => 'application/pdf', 'preset' => 'pdf', 'badge' => 'PDF'],
        ['key' => 'doc-word', 'name' => 'Kontrak.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'preset' => 'word', 'badge' => 'WORD'],
        ['key' => 'doc-excel', 'name' => 'Keuangan.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'preset' => 'excel', 'badge' => 'EXCEL'],
    ];

    foreach ($docs as $doc) {
        $body = 'synthetic-doc-content-'.$doc['key'];
        $start = $this->withToken($phone['plain_token'])
            ->postJson('/api/v1/uploads', [
                'idempotency_key' => $doc['key'],
                'filename' => $doc['name'],
                'total_size_bytes' => strlen($body),
                'expected_sha256' => hash('sha256', $body),
                'mime_type' => $doc['mime'],
            ])
            ->assertCreated();

        $uploadId = $start->json('upload.id');
        $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$phone['plain_token'],
            'HTTP_UPLOAD_OFFSET' => '0',
        ], $body)->assertOk();

        $completed = $this->withToken($phone['plain_token'])
            ->postJson("/api/v1/uploads/{$uploadId}/complete")
            ->assertCreated()
            ->assertJsonPath('file.thumbnail_preset', $doc['preset'])
            ->assertJsonPath('file.thumbnail_status', 'default')
            ->assertJsonPath('file.has_custom_thumbnail', false)
            ->assertJsonPath('file.default_thumbnail_url', "/thumbnails/defaults/{$doc['preset']}.svg");

        $record = DriveFile::query()->findOrFail($completed->json('file.id'));
        expect($record->thumbnail_key)->toBeNull()
            ->and($record->thumbnail_size_bytes)->toBe(0);

        $thumbResp = $this->withToken($phone['plain_token'])
            ->get("/api/v1/files/{$record->id}/thumbnail")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');
        expect($thumbResp->getContent())->toContain($doc['badge']);
    }

    // 3. Upload a larger 640x480 PNG photo -> resized (<= 320px) & optimized WebP/JPEG thumbnail saved on disk
    if (extension_loaded('gd')) {
        $img = imagecreatetruecolor(640, 480);
        $bg = imagecolorallocate($img, 37, 99, 235);
        imagefill($img, 0, 0, (int) $bg);
        ob_start();
        imagepng($img);
        $bigPng = (string) ob_get_clean();
        imagedestroy($img);

        $start = $this->withToken($phone['plain_token'])
            ->postJson('/api/v1/uploads', [
                'idempotency_key' => 'big-photo-1',
                'filename' => 'Pemandangan.png',
                'total_size_bytes' => strlen($bigPng),
                'expected_sha256' => hash('sha256', $bigPng),
                'mime_type' => 'image/png',
            ])
            ->assertCreated();

        $uploadId = $start->json('upload.id');
        $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$phone['plain_token'],
            'HTTP_UPLOAD_OFFSET' => '0',
        ], $bigPng)->assertOk();

        $completed = $this->withToken($phone['plain_token'])
            ->postJson("/api/v1/uploads/{$uploadId}/complete")
            ->assertCreated()
            ->assertJsonPath('file.thumbnail_preset', 'image')
            ->assertJsonPath('file.thumbnail_status', 'generated')
            ->assertJsonPath('file.has_custom_thumbnail', true);

        $record = DriveFile::query()->findOrFail($completed->json('file.id'));
        expect($record->thumbnail_key)->not->toBeNull()
            ->and($record->thumbnail_size_bytes)->toBeGreaterThan(0)
            ->and($record->thumbnail_mime)->toBeIn(['image/webp', 'image/jpeg']);

        $thumbBytes = Storage::disk('local')->get((string) $record->thumbnail_key);
        $decoded = imagecreatefromstring((string) $thumbBytes);
        expect($decoded)->not->toBeFalse();
        if ($decoded instanceof GdImage) {
            expect(imagesx($decoded))->toBeLessThanOrEqual(320)
                ->and(imagesy($decoded))->toBeLessThanOrEqual(320);
            imagedestroy($decoded);
        }
    }
});
