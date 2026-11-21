<?php

use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\VaultService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('private vault enforces device-bound sessions, 5-minute idle lock, move-in isolation, and recovery', function () {
    $user = User::factory()->create();
    $credentials = app(ApiCredentialService::class);
    $vault = app(VaultService::class);

    $phoneA = $credentials->issueDeviceToken($user, 'Ponsel A', 'android');
    $phoneB = $credentials->issueDeviceToken($user, 'Ponsel B', 'ios');

    $vault->setPassphrase($user, 'frasa-rahasia-99');

    // Upload a regular file first
    $content = 'dokumen-penting-sekali';
    $start = $this->withToken($phoneA['plain_token'])
        ->postJson('/api/v1/uploads', [
            'idempotency_key' => 'secret-doc',
            'filename' => 'Pajak.pdf',
            'total_size_bytes' => strlen($content),
            'expected_sha256' => hash('sha256', $content),
            'mime_type' => 'application/pdf',
        ])
        ->assertCreated();

    $uploadId = $start->json('upload.id');
    $this->call('PATCH', "/api/v1/uploads/{$uploadId}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$phoneA['plain_token'],
        'HTTP_UPLOAD_OFFSET' => '0',
    ], $content)
        ->assertOk();

    $fileId = $this->withToken($phoneA['plain_token'])
        ->postJson("/api/v1/uploads/{$uploadId}/complete")
        ->assertCreated()
        ->json('file.id');

    // Locked vault rejects listing
    $this->withToken($phoneA['plain_token'])
        ->getJson('/api/v1/vault/files')
        ->assertForbidden()
        ->assertJsonPath('code', 'vault_locked');

    // Unlock on Phone A
    $unlock = $this->withToken($phoneA['plain_token'])
        ->postJson('/api/v1/vault/session', ['passphrase' => 'frasa-rahasia-99'])
        ->assertOk();

    expect((string) $unlock->headers->get('Cache-Control'))->toContain('no-store');
    $vaultToken = $unlock->json('vault_token');

    // Phone B cannot reuse Phone A's vault token
    $this->withToken($phoneB['plain_token'])
        ->withHeader('X-Vault-Token', $vaultToken)
        ->getJson('/api/v1/vault/files')
        ->assertForbidden()
        ->assertJsonPath('code', 'vault_locked');

    // Move regular file into Private Vault
    $this->withToken($phoneA['plain_token'])
        ->withHeader('X-Vault-Token', $vaultToken)
        ->postJson("/api/v1/vault/files/{$fileId}/move-in")
        ->assertOk()
        ->assertJsonPath('file.space', 'private');

    // File is hidden from regular listing & search
    $this->withToken($phoneA['plain_token'])
        ->getJson('/api/v1/files?q=Pajak')
        ->assertOk()
        ->assertJsonCount(0, 'files');

    // Accessing private content without vault token returns generic 404 (no existence leak)
    $this->flushHeaders()
        ->withToken($phoneA['plain_token'])
        ->get("/api/v1/files/{$fileId}/content")
        ->assertNotFound();

    // Accessing private content WITH valid vault token succeeds with no-store header
    $privateDownload = $this->withToken($phoneA['plain_token'])
        ->withHeader('X-Vault-Token', $vaultToken)
        ->get("/api/v1/files/{$fileId}/content")
        ->assertOk();

    expect((string) $privateDownload->headers->get('Cache-Control'))->toContain('no-store')
        ->and($privateDownload->streamedContent())->toBe($content);

    // Background poll does not extend session; after 6 minutes session expires
    $this->travel(4)->minutes();
    $this->withToken($phoneA['plain_token'])
        ->withHeaders(['X-Vault-Token' => $vaultToken, 'X-Background-Poll' => '1'])
        ->getJson('/api/v1/vault/files')
        ->assertOk();

    $this->travel(2)->minutes();
    $this->withToken($phoneA['plain_token'])
        ->withHeader('X-Vault-Token', $vaultToken)
        ->getJson('/api/v1/vault/files')
        ->assertForbidden()
        ->assertJsonPath('code', 'vault_locked');

    // Admin CLI recovery resets passphrase and revokes active sessions
    $unlock2 = $this->withToken($phoneA['plain_token'])
        ->postJson('/api/v1/vault/session', ['passphrase' => 'frasa-rahasia-99'])
        ->assertOk();
    $token2 = $unlock2->json('vault_token');

    $this->artisan('gunydrive:vault-recover', [
        '--email' => $user->email,
        '--passphrase' => 'frasa-baru-2026',
    ])->assertSuccessful();

    $this->withToken($phoneA['plain_token'])
        ->withHeader('X-Vault-Token', $token2)
        ->getJson('/api/v1/vault/files')
        ->assertForbidden()
        ->assertJsonPath('code', 'vault_locked');
});

test('wrong vault passphrases trigger rate limit lockout', function () {
    $user = User::factory()->create();
    $phone = app(ApiCredentialService::class)->issueDeviceToken($user, 'Ponsel', 'android');
    app(VaultService::class)->setPassphrase($user, 'benar-sekali');

    for ($i = 0; $i < 5; $i++) {
        $this->withToken($phone['plain_token'])
            ->postJson('/api/v1/vault/session', ['passphrase' => 'salah'])
            ->assertForbidden()
            ->assertJsonPath('code', 'vault_invalid_passphrase');
    }

    $this->withToken($phone['plain_token'])
        ->postJson('/api/v1/vault/session', ['passphrase' => 'benar-sekali'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'vault_rate_limited');
});
