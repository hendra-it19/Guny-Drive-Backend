<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('unverified users are redirected to the email verification prompt', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('appearance.edit'));

    $response->assertRedirect(route('verification.notice'));
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('email.verified', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with invalid user id', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => 123, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('verified user is redirected to confirmation from verification prompt', function () {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('email.verified', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('email.verified', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('mobile registration requires email verification before a device token is issued', function () {
    Notification::fake();
    $email = 'mobile@example.test';
    $credentials = ['login' => $email, 'password' => 'mobile-test-password'];

    $this->postJson('/api/v1/auth/register', ['email' => $email, 'password' => $credentials['password']])
        ->assertCreated()->assertJsonPath('code', 'email_verification_required')->assertJsonMissingPath('token');
    $user = User::where('email', $email)->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->postJson('/api/v1/auth/login', $credentials)->assertForbidden();
    $this->postJson('/api/v1/auth/verification/resend', $credentials)->assertOk();
    expect($user->apiCredentials()->count())->toBe(0);

    $user->markEmailAsVerified();
    $this->postJson('/api/v1/auth/login', $credentials)->assertOk()->assertJsonStructure(['token']);
});

test('mobile user can verify email via signed link without an active web session', function () {
    Notification::fake();
    $email = 'guest-mobile@example.test';
    $password = 'sandi-aman-123';

    $this->postJson('/api/v1/auth/register', ['email' => $email, 'password' => $password])
        ->assertCreated()
        ->assertJsonPath('code', 'email_verification_required');

    $user = User::where('email', $email)->firstOrFail();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->get($verificationUrl)
        ->assertRedirect(route('email.verified', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->get(route('email.verified', absolute: false).'?verified=1')->assertOk();

    $this->postJson('/api/v1/auth/login', ['login' => $email, 'password' => $password])
        ->assertOk()
        ->assertJsonStructure(['token', 'username']);
});

test('mobile user can verify email using the 6-digit verification code and log in immediately', function () {
    Notification::fake();
    $email = 'otp-mobile@example.test';
    $password = 'sandi-aman-123';

    $this->postJson('/api/v1/auth/register', ['email' => $email, 'password' => $password])
        ->assertCreated()
        ->assertJsonPath('code', 'email_verification_required');

    $user = User::where('email', $email)->firstOrFail();
    $code = $user->issueEmailVerificationCode();

    $this->postJson('/api/v1/auth/verification/verify', [
        'login' => $email,
        'code' => '000000',
        'password' => $password,
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_verification_code');

    $this->postJson('/api/v1/auth/verification/verify', [
        'login' => $email,
        'code' => $code,
        'password' => $password,
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'username']);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('verification message uses the mobile theme and includes a signed plain text link', function () {
    $user = User::factory()->unverified()->create();
    request()->merge(['theme' => 'zamrud', 'scheme' => 'dark']);
    $mail = (new VerifyEmail)->toMail($user);
    $data = $mail->viewData;
    expect(view($mail->view[0], $data)->render())->toContain('#5FE0A8', 'Verifikasi email saya')
        ->and(view($mail->view[1], $data)->render())->toContain($data['url'])
        ->and(URL::hasValidSignature(Request::create($data['url'])))->toBeTrue();
});
