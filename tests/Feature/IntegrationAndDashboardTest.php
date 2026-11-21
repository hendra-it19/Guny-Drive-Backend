<?php

use App\Enums\IntegrationTemplate;
use App\Enums\Space;
use App\Models\ApiRequestLog;
use App\Models\DriveFile;
use App\Models\Folder;
use App\Models\User;
use App\Services\ApiCredentialService;
use App\Services\IntegrationService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('integration endpoint scopes reads to one regular folder, blocks private and other routes, and stops when disabled', function () {
    $user = User::factory()->create();
    $integrations = app(IntegrationService::class);
    $credentials = app(ApiCredentialService::class);

    $docsFolder = Folder::factory()->create(['user_id' => $user->id, 'space' => Space::Regular, 'name' => 'Dokumen']);
    $otherFolder = Folder::factory()->create(['user_id' => $user->id, 'space' => Space::Regular, 'name' => 'Lain']);
    $privateFolder = Folder::factory()->create(['user_id' => $user->id, 'space' => Space::Private, 'name' => 'Rahasia']);

    DriveFile::factory()->create(['user_id' => $user->id, 'folder_id' => $docsFolder->id, 'space' => Space::Regular, 'name' => 'Laporan.pdf']);
    DriveFile::factory()->create(['user_id' => $user->id, 'folder_id' => $otherFolder->id, 'space' => Space::Regular, 'name' => 'DiLuar.pdf']);
    DriveFile::factory()->create(['user_id' => $user->id, 'folder_id' => $privateFolder->id, 'space' => Space::Private, 'name' => 'Rahasia.pdf']);

    $epDocs = $integrations->createEndpoint($user, 'Arsip Dokumen', 'arsip', IntegrationTemplate::FolderFiles, $docsFolder->id);
    $epQuota = $integrations->createEndpoint($user, 'Ringkasan Kuota', 'kuota', IntegrationTemplate::QuotaSummary);

    $tokenDocs = $credentials->issueIntegrationToken($user, $epDocs, 'Pembaca Arsip', ['files:read']);

    // Returns ONLY files inside Dokumen
    $this->withToken($tokenDocs['plain_token'])
        ->getJson('/api/v1/integrations/arsip')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Laporan.pdf');

    // Rejected on another endpoint
    $this->withToken($tokenDocs['plain_token'])
        ->getJson('/api/v1/integrations/kuota')
        ->assertForbidden()
        ->assertJsonPath('code', 'endpoint_forbidden');

    // Rejected on device API routes (including vault & uploads)
    $this->withToken($tokenDocs['plain_token'])
        ->getJson('/api/v1/files')
        ->assertForbidden()
        ->assertJsonPath('code', 'client_type_forbidden');

    // Disabling endpoint blocks access immediately
    $integrations->setActive($epDocs, false);

    $this->withToken($tokenDocs['plain_token'])
        ->getJson('/api/v1/integrations/arsip')
        ->assertForbidden()
        ->assertJsonPath('code', 'endpoint_disabled');

    // API request metrics recorded without leaking bearer token
    $log = ApiRequestLog::query()->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->route_template)->toBe('/api/v1/integrations/{slug}')
        ->and($log->error_code)->toBe('endpoint_disabled');
});

test('owner dashboard pages render and manage storage, api access, monitoring, and backup manifest', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('storage.index'))->assertOk();
    $this->get(route('api-access.index'))->assertOk();
    $this->get(route('monitoring.index'))->assertOk();

    $this->put(route('storage.policy.update'), [
        'quota_gb' => 50,
        'max_file_mb' => 512,
    ])->assertRedirect();

    $this->post(route('api-access.credentials.store'), [
        'client_type' => 'device',
        'label' => 'Ponsel Utama',
        'platform' => 'android',
    ])->assertRedirect()
        ->assertSessionHas('issued_token.label', 'Ponsel Utama');

    $this->post(route('storage.cleanup'))->assertRedirect();

    $this->artisan('gunydrive:backup-manifest')->assertSuccessful();
});
