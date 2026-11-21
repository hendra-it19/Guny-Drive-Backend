<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiErrorException;
use App\Http\Controllers\Controller;
use App\Models\ApiCredential;
use App\Models\User;
use App\Services\ApiCredentialService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'email' => 'required|email|max:254',
                'password' => 'required|string|min:8|max:72',
            ],
            [
                'email.required' => 'Alamat email wajib diisi.',
                'email.email' => 'Format alamat email tidak valid.',
                'email.max' => 'Alamat email maksimal 254 karakter.',
                'password.required' => 'Kata sandi wajib diisi.',
                'password.min' => 'Kata sandi minimal 8 karakter.',
                'password.max' => 'Kata sandi maksimal 72 karakter.',
            ],
        );

        $email = strtolower(trim($data['email']));
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => User::availableUsername($email),
                'password' => $data['password'],
            ],
        );

        if (! $user->wasRecentlyCreated) {
            throw ValidationException::withMessages(['email' => 'Alamat email sudah digunakan.']);
        }

        $user->forceFill(['username' => User::availableUsername($email)])->save();

        $this->dispatchVerificationWithRequestHost($request, function () use ($user): void {
            event(new Registered($user));
        });

        return response()->json([
            'code' => 'email_verification_required',
            'username' => $user->username,
            'message' => 'Kode verifikasi 6 digit dan tautan aktivasi telah dikirim ke email Anda.',
        ], 201);
    }

    public function login(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        return $this->device($this->passwordUser($request), $credentials);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $this->passwordUser($request);
        if (! $user->hasVerifiedEmail()) {
            $this->dispatchVerificationWithRequestHost($request, function () use ($user): void {
                $user->sendEmailVerificationNotification();
            });
        }

        return response()->json([
            'message' => 'Kode verifikasi 6 digit dan tautan baru telah dikirim ke email Anda.',
        ]);
    }

    public function verifyCode(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        $data = $request->validate(
            [
                'login' => 'required|string|max:254',
                'code' => 'required|digits:6',
                'password' => 'nullable|string|max:256',
            ],
            [
                'login.required' => 'Email atau username wajib diisi.',
                'code.required' => 'Kode verifikasi 6 digit wajib diisi.',
                'code.digits' => 'Kode verifikasi harus terdiri dari 6 digit angka.',
            ],
        );

        $login = strtolower(trim($data['login']));
        $user = User::where(str_contains($login, '@') ? 'email' : 'username', $login)->first();

        if (! $user || ! $user->verifyEmailCode($data['code'])) {
            throw new ApiErrorException(
                'invalid_verification_code',
                'Kode verifikasi 6 digit tidak valid atau sudah kedaluwarsa.',
                422,
            );
        }

        if (isset($data['password']) && $data['password'] !== '' && Hash::check($data['password'], $user->password)) {
            return $this->device($user, $credentials);
        }

        return response()->json([
            'verified' => true,
            'username' => $user->username,
            'message' => 'Email berhasil diverifikasi. Silakan masuk dengan akun Anda.',
        ]);
    }

    private function passwordUser(Request $request): User
    {
        $data = $request->validate(
            [
                'login' => 'required|string|max:254',
                'password' => 'required|string|max:256',
            ],
            [
                'login.required' => 'Email atau username wajib diisi.',
                'password.required' => 'Kata sandi wajib diisi.',
            ],
        );

        $login = strtolower(trim($data['login']));
        $user = User::where(str_contains($login, '@') ? 'email' : 'username', $login)->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new ApiErrorException('invalid_credentials', 'Identitas atau kata sandi salah.', 401);
        }

        return $user;
    }

    public function exchange(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|alpha_num:ascii|size:64',
            'verifier' => 'required|alpha_num:ascii|size:64',
        ]);
        $key = 'mobile-grant:'.$data['code'];

        return Cache::lock($key.':lock', 10)->block(2, function () use ($key, $data, $credentials): JsonResponse {
            $grant = Cache::get($key);
            abort_unless(is_array($grant) && hash_equals($grant['challenge'], hash('sha256', $data['verifier'])), 401);
            Cache::forget($key);
            Cache::forget('mobile-challenge-grant:'.$grant['challenge']);

            return $this->device(User::whereKey($grant['user'])->firstOrFail(), $credentials);
        });
    }

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => 'required|regex:/^[a-f0-9]{64}$/',
        ]);

        $code = Cache::pull('mobile-challenge-grant:'.$data['challenge']);

        return response()->json([
            'ready' => is_string($code) && $code !== '',
            'code' => is_string($code) && $code !== '' ? $code : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        $credential = $request->attributes->get('api_credential');
        abort_unless($credential instanceof ApiCredential, 401);
        $credentials->revoke($credential);

        return response()->json(['revoked' => true]);
    }

    public function enrollPasskey(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $plainPasskey = 'gd_pk_'.Str::random(56);

        $user->forceFill([
            'biometric_token_hash' => hash('sha256', $plainPasskey),
        ])->save();

        return response()->json([
            'passkey_token' => $plainPasskey,
            'username' => $user->username,
            'email' => $user->email,
            'message' => 'Passkey biometrik berhasil diaktifkan di perangkat ini.',
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function disablePasskey(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['biometric_token_hash' => null])->save();

        return response()->json([
            'enabled' => false,
            'message' => 'Passkey biometrik telah dinonaktifkan.',
        ]);
    }

    public function passkeyLogin(Request $request, ApiCredentialService $credentials): JsonResponse
    {
        $data = $request->validate(
            [
                'passkey_token' => 'required|string|min:32|max:128',
            ],
            [
                'passkey_token.required' => 'Token passkey tidak ditemukan di perangkat ini.',
            ],
        );

        $hash = hash('sha256', trim($data['passkey_token']));
        $user = User::where('biometric_token_hash', $hash)->first();

        if (! $user) {
            throw new ApiErrorException(
                'invalid_passkey',
                'Passkey pada perangkat ini sudah tidak berlaku atau telah dinonaktifkan. Silakan masuk dengan akun Anda lalu aktifkan kembali Passkey.',
                401,
            );
        }

        return $this->device($user, $credentials);
    }

    private function device(User $user, ApiCredentialService $credentials): JsonResponse
    {
        if (! $user->hasVerifiedEmail()) {
            throw new ApiErrorException(
                'email_verification_required',
                'Verifikasi alamat email Anda dengan kode 6 digit atau tautan di email sebelum masuk.',
                403,
            );
        }

        $issued = $credentials->issueDeviceToken($user, 'GunyDrive Mobile', null, now()->addDays(90));

        return response()->json([
            'token' => $issued['plain_token'],
            'username' => $user->username,
        ])->header('Cache-Control', 'no-store');
    }

    private function dispatchVerificationWithRequestHost(Request $request, callable $callback): void
    {
        $host = $request->getHost();
        $customRoot = $host !== '' && $host !== 'localhost' && $host !== '127.0.0.1'
            ? $request->getSchemeAndHttpHost()
            : null;

        if ($customRoot !== null) {
            URL::forceRootUrl($customRoot);
        }

        try {
            $callback();
        } finally {
            if ($customRoot !== null) {
                URL::forceRootUrl(null);
            }
        }
    }
}
