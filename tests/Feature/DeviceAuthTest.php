<?php

use App\Models\User;
use App\Services\ApiCredentialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

test('owner can be created via console command and public registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->artisan('gunydrive:create-owner', [
        '--name' => 'Hendra',
        '--email' => 'owner@example.com',
        '--password' => 'rahasia-panjang-123',
    ])->assertSuccessful();

    $owner = User::query()->where('email', 'owner@example.com')->first();
    expect($owner)->not->toBeNull()
        ->and($owner->email_verified_at)->not->toBeNull();
});

test('revoking phone A rejects subsequent requests while phone B stays connected', function () {
    $user = User::factory()->create();
    $service = app(ApiCredentialService::class);

    $phoneA = $service->issueDeviceToken($user, 'Ponsel A', 'android');
    $phoneB = $service->issueDeviceToken($user, 'Ponsel B', 'ios');

    $this->withToken($phoneA['plain_token'])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('device.name', 'Ponsel A');

    $service->revoke($phoneA['credential']);

    $this->withToken($phoneA['plain_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'token_revoked');

    $this->withToken($phoneB['plain_token'])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('device.name', 'Ponsel B');
});

test('expired device token is rejected and device token cannot open web dashboard', function () {
    $user = User::factory()->create();
    $service = app(ApiCredentialService::class);

    $expired = $service->issueDeviceToken($user, 'Ponsel Lama', 'android', now()->subMinute());

    $this->withToken($expired['plain_token'])
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'token_expired');

    $active = $service->issueDeviceToken($user, 'Ponsel Aktif', 'android');

    $this->withToken($active['plain_token'])
        ->get('/dashboard')
        ->assertRedirect('/login');
});

test('mobile app can create private folder PIN, enroll biometric passkey, and store Google profile avatar', function () {
    Storage::fake('public');
    Http::fake([
        'https://lh3.googleusercontent.com/*' => Http::response('fake-jpeg-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $user = User::factory()->create();
    $user->storeRemoteAvatar('https://lh3.googleusercontent.com/a/default-user=s96-c');

    expect($user->fresh()->avatar_path)->not->toBeNull()
        ->and($user->fresh()->avatarUrl())->toContain('/avatars/'.$user->id);

    $this->get('/avatars/'.$user->id)->assertOk();

    $service = app(ApiCredentialService::class);
    $issued = $service->issueDeviceToken($user, 'Ponsel Utama', 'android');
    $deviceToken = $issued['plain_token'];

    // 1. Setup Private Folder PIN and confirmation from mobile app
    $setupRes = $this->withToken($deviceToken)
        ->postJson('/api/v1/vault/setup', [
            'pin' => '123456',
            'pin_confirmation' => '123456',
        ])
        ->assertCreated()
        ->assertJsonPath('configured', true)
        ->assertJsonStructure(['vault_token', 'expires_at']);

    expect($setupRes->json('vault_token'))->toStartWith('gd_vlt_');

    // Subsequent unlock with the same PIN
    $this->withToken($deviceToken)
        ->postJson('/api/v1/vault/session', [
            'passphrase' => '123456',
        ])
        ->assertOk()
        ->assertJsonStructure(['vault_token']);

    // 2. Enroll Biometric Passkey (mobile-banking style)
    $enrollRes = $this->withToken($deviceToken)
        ->postJson('/api/v1/auth/passkey/enroll')
        ->assertCreated()
        ->assertJsonStructure(['passkey_token', 'email']);

    $passkeyToken = (string) $enrollRes->json('passkey_token');

    // Login using enrolled passkey token
    $this->postJson('/api/v1/auth/passkey/login', [
        'passkey_token' => $passkeyToken,
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'username']);

    // Disable passkey
    $this->withToken($deviceToken)
        ->deleteJson('/api/v1/auth/passkey')
        ->assertOk()
        ->assertJsonPath('enabled', false);

    // 3. Google OAuth start includes redirect_uri and supports Expo Go return_url + sslip.io LAN callback
    config(['services.google.client_id' => 'test-google-client-id']);
    $challenge = str_repeat('a', 64);
    $expoReturnUrl = urlencode('exp://192.168.100.18:8081/--/settings/connect');
    $googleRedirect = $this->get(
        'http://192.168.100.18:8000/mobile/start?provider=google&challenge='.$challenge.'&return_url='.$expoReturnUrl
    );
    $googleRedirect->assertRedirect();
    expect($googleRedirect->headers->get('Location'))
        ->toContain(urlencode('http://192.168.100.18.sslip.io:8000/mobile/google/callback'));

    $this->getJson('/api/v1/auth/poll?challenge='.$challenge)
        ->assertOk()
        ->assertJsonPath('ready', false);
});
