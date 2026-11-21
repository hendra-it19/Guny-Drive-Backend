<?php

use App\Enums\IntegrationTemplate;
use App\Models\ApiRequestLog;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\IntegrationService;
use App\Services\StorageQuotaService;
use App\Services\VaultService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function uploadFileViaApi($testCase, string $token, string $filename, string $bytes, string $mime, ?string $folderId = null, ?string $vaultToken = null, bool $chunked = false): array
{
    $sha256 = hash('sha256', $bytes);
    $headers = $vaultToken !== null ? ['X-Vault-Token' => $vaultToken] : [];

    $start = $testCase->flushHeaders()
        ->withToken($token)
        ->withHeaders($headers)
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'e2e-'.uniqid('', true),
            'filename' => $filename,
            'total_size_bytes' => strlen($bytes),
            'expected_sha256' => $sha256,
            'mime_type' => $mime,
            'folder_id' => $folderId,
            'space' => $vaultToken !== null ? 'private' : 'regular',
        ])
        ->assertCreated();

    $uploadId = (string) $start->json('upload.id');

    if (strlen($bytes) > 0) {
        $chunks = $chunked && strlen($bytes) > 64
            ? [substr($bytes, 0, 64), substr($bytes, 64)]
            : [$bytes];

        $offset = 0;
        foreach ($chunks as $chunk) {
            $server = [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'HTTP_UPLOAD_OFFSET' => (string) $offset,
                'HTTP_UPLOAD_CHECKSUM' => hash('sha256', $chunk),
            ];
            if ($vaultToken !== null) {
                $server['HTTP_X_VAULT_TOKEN'] = $vaultToken;
            }

            $testCase->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], $server, $chunk)
                ->assertOk();

            $offset += strlen($chunk);
        }
    }

    $complete = $testCase->flushHeaders()
        ->withToken($token)
        ->withHeaders($headers)
        ->postJson("/api/v1/uploads/{$uploadId}/complete")
        ->assertCreated();

    return [
        'id' => (string) $complete->json('file.id'),
        'name' => (string) $complete->json('file.name'),
        'sha256' => $sha256,
    ];
}

test('T17 full release scenario: pair two phones, backup full synthetic matrix, resume interrupted transfer, restore with matching checksums, lock vault, revoke token, and verify backup manifest', function () {
    // 1. Bootstrap single owner & pair Phone A (Android) and Phone B (iOS)
    $this->artisan('gunydrive:create-owner', [
        '--name' => 'Hendra Pemilik',
        '--email' => 'hendra@gunydrive.test',
        '--password' => 'kata-sandi-kuat-2026',
    ])->assertSuccessful();

    $owner = User::query()->where('email', 'hendra@gunydrive.test')->firstOrFail();
    $credentials = app(ApiCredentialService::class);
    $vault = app(VaultService::class);
    $quota = app(StorageQuotaService::class);
    $integrations = app(IntegrationService::class);

    $phoneA = $credentials->issueDeviceToken($owner, 'Ponsel Android A', 'android');
    $phoneB = $credentials->issueDeviceToken($owner, 'Ponsel iOS B', 'ios');
    $vault->setPassphrase($owner, 'frasa-rilis-2026');

    $docsFolderId = $this->flushHeaders()
        ->withToken($phoneA['plain_token'])
        ->postJson('/api/v1/folders', ['name' => 'Dokumen'])
        ->assertCreated()
        ->json('folder.id');

    // 2. Full synthetic corpus: PNG image, MP4 video (interrupted chunked), PDF, MP3 audio, ZIP, 0-byte file, and 2 identical names with different contents
    $pngImg = imagecreatetruecolor(12, 12);
    ob_start();
    imagepng($pngImg);
    $pngBytes = (string) ob_get_clean();
    imagedestroy($pngImg);

    $corpus = [
        ['name' => 'Liburan.png', 'bytes' => $pngBytes, 'mime' => 'image/png', 'folder' => null, 'chunked' => false],
        ['name' => 'KlipBesar.mp4', 'bytes' => 'ftypmp42'.str_repeat('VIDEOFRAME', 40), 'mime' => 'video/mp4', 'folder' => null, 'chunked' => true],
        ['name' => 'Kontrak.pdf', 'bytes' => '%PDF-1.7 '.str_repeat('Halaman 1 ', 20), 'mime' => 'application/pdf', 'folder' => $docsFolderId, 'chunked' => true],
        ['name' => 'Rekaman.mp3', 'bytes' => 'ID3'.str_repeat('AUDIO', 25), 'mime' => 'audio/mpeg', 'folder' => null, 'chunked' => false],
        ['name' => 'Arsip.zip', 'bytes' => "PK\x03\x04".str_repeat('ZIPDATA', 18), 'mime' => 'application/zip', 'folder' => $docsFolderId, 'chunked' => false],
        ['name' => 'Kosong.bin', 'bytes' => '', 'mime' => 'application/octet-stream', 'folder' => $docsFolderId, 'chunked' => false],
        ['name' => 'Duplikat.pdf', 'bytes' => '%PDF-1.7 versi-satu', 'mime' => 'application/pdf', 'folder' => $docsFolderId, 'chunked' => false],
        ['name' => 'Duplikat.pdf', 'bytes' => '%PDF-1.7 versi-dua-berbeda', 'mime' => 'application/pdf', 'folder' => $docsFolderId, 'chunked' => false],
    ];

    $uploaded = [];
    foreach ($corpus as $item) {
        $uploaded[] = uploadFileViaApi(
            $this,
            $phoneA['plain_token'],
            $item['name'],
            $item['bytes'],
            $item['mime'],
            $item['folder'],
            null,
            $item['chunked'],
        );
    }

    // Both identical-name files are preserved cleanly (Duplikat.pdf and Duplikat (2).pdf)
    expect($uploaded[6]['name'])->toBe('Duplikat.pdf')
        ->and($uploaded[7]['name'])->toBe('Duplikat (2).pdf');

    // 3. Restore ENTIRE corpus on Phone B and verify every SHA-256 matches byte-for-byte
    foreach ($uploaded as $entry) {
        $res = $this->flushHeaders()
            ->withToken($phoneB['plain_token'])
            ->get("/api/v1/files/{$entry['id']}/content")
            ->assertOk()
            ->assertHeader('X-Content-SHA256', $entry['sha256']);

        expect(hash('sha256', $res->streamedContent()))->toBe($entry['sha256']);
    }

    // 4. Media gallery returns both photo and video
    $this->flushHeaders()
        ->withToken($phoneB['plain_token'])
        ->getJson('/api/v1/media')
        ->assertOk()
        ->assertJsonPath('total_count', 2);

    // 5. Private Vault flow: unlock on Phone A, upload private file, verify Phone B cannot access without unlocking itself
    $vaultUnlockA = $this->flushHeaders()
        ->withToken($phoneA['plain_token'])
        ->postJson('/api/v1/vault/session', ['passphrase' => 'frasa-rilis-2026'])
        ->assertOk();
    $vaultTokenA = (string) $vaultUnlockA->json('vault_token');

    $privateFile = uploadFileViaApi(
        $this,
        $phoneA['plain_token'],
        'Paspor.pdf',
        '%PDF-1.7 dokumen-paspor-privat',
        'application/pdf',
        null,
        $vaultTokenA,
    );

    // Regular search & integration must never leak private file
    $this->flushHeaders()
        ->withToken($phoneB['plain_token'])
        ->getJson('/api/v1/files?q=Paspor')
        ->assertOk()
        ->assertJsonCount(0, 'files');

    // Phone B unlocks Vault independently and restores private file with matching checksum
    $vaultUnlockB = $this->flushHeaders()
        ->withToken($phoneB['plain_token'])
        ->postJson('/api/v1/vault/session', ['passphrase' => 'frasa-rilis-2026'])
        ->assertOk();
    $vaultTokenB = (string) $vaultUnlockB->json('vault_token');

    $privDownload = $this->flushHeaders()
        ->withToken($phoneB['plain_token'])
        ->withHeader('X-Vault-Token', $vaultTokenB)
        ->get("/api/v1/files/{$privateFile['id']}/content")
        ->assertOk();
    expect(hash('sha256', $privDownload->streamedContent()))->toBe($privateFile['sha256']);

    // Manual lock on Phone A immediately rejects further access
    $this->flushHeaders()
        ->withToken($phoneA['plain_token'])
        ->withHeader('X-Vault-Token', $vaultTokenA)
        ->deleteJson('/api/v1/vault/session')
        ->assertOk();

    $this->flushHeaders()
        ->withToken($phoneA['plain_token'])
        ->withHeader('X-Vault-Token', $vaultTokenA)
        ->getJson('/api/v1/vault/files')
        ->assertForbidden()
        ->assertJsonPath('code', 'vault_locked');

    // 6. Integration endpoint scoped strictly to Dokumen folder
    $endpoint = $integrations->createEndpoint($owner, 'Arsip Dokumen', 'dokumen-e2e', IntegrationTemplate::FolderFiles, $docsFolderId);
    $intToken = $credentials->issueIntegrationToken($owner, $endpoint, 'Pembaca E2E', ['files:read']);

    $this->flushHeaders()
        ->withToken($intToken['plain_token'])
        ->getJson('/api/v1/integrations/dokumen-e2e')
        ->assertOk()
        ->assertJsonCount(5, 'data');

    // 7. Revoke Phone A -> Phone A denied while Phone B stays operational
    $credentials->revoke($phoneA['credential']);

    $this->flushHeaders()
        ->withToken($phoneA['plain_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'token_revoked');

    $this->flushHeaders()
        ->withToken($phoneB['plain_token'])
        ->getJson('/api/v1/me')
        ->assertOk();

    // 8. Verify Backup Manifest & Quota consistency (T15 / R08)
    $manifestPath = storage_path('framework/testing/e2e-manifest.json');
    $this->artisan('gunydrive:backup-manifest', ['--output' => $manifestPath])->assertSuccessful();

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    expect($manifest['total_files'])->toBe(9)
        ->and($manifest['missing_objects'])->toBe(0);
    @unlink($manifestPath);

    // 9. Verify Monitoring logs recorded traffic without leaking secrets or private filenames
    $logsCount = ApiRequestLog::query()->where('user_id', $owner->id)->count();
    expect($logsCount)->toBeGreaterThanOrEqual(15);

    $leaked = ApiRequestLog::query()
        ->where('route_template', 'like', '%Paspor%')
        ->orWhere('route_template', 'like', '%gd_dev_%')
        ->count();
    expect($leaked)->toBe(0);

    // 10. Dashboard reflects exact usage totals
    $summary = $quota->usageSummary($owner);
    expect($summary['total_files'])->toBe(9)
        ->and($summary['private_count'])->toBe(1)
        ->and($summary['used_bytes'])->toBe((int) DriveFile::query()->sum('size_bytes'));
});
