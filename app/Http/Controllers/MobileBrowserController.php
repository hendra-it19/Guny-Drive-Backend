<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

class MobileBrowserController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $params = $request->validate([
            'challenge' => 'required|regex:/^[a-f0-9]{64}$/',
            'provider' => 'required|in:passkey,google',
            'return_url' => 'nullable|string|max:500',
        ]);

        $returnUrl = $this->sanitizeReturnUrl($params['return_url'] ?? null);
        $statePayload = [
            'challenge' => $params['challenge'],
            'return_url' => $returnUrl,
        ];

        $request->session()->put('mobile_challenge', $params['challenge']);
        $request->session()->put('mobile_return_url', $returnUrl);
        Cache::put('mobile_latest_challenge', $statePayload, now()->addMinutes(10));

        if ($params['provider'] === 'google') {
            abort_unless(config('services.google.client_id'), 503, 'Google Login belum dikonfigurasi.');

            $state = Str::random(40);
            Cache::put('mobile_google_state:'.$state, $statePayload, now()->addMinutes(10));

            return $this->googleDriver($request)
                ->with(['state' => $state, 'prompt' => 'select_account'])
                ->redirect();
        }

        $request->session()->put('url.intended', route('mobile.consent'));

        return redirect()->route(Auth::check() ? 'mobile.consent' : 'login');
    }

    public function google(Request $request): RedirectResponse|JsonResponse
    {
        $state = (string) $request->query('state', '');
        $rawState = ($state !== '' ? Cache::pull('mobile_google_state:'.$state) : null)
            ?? $request->session()->pull('mobile_challenge')
            ?? Cache::pull('mobile_latest_challenge');

        $challenge = is_array($rawState)
            ? (string) ($rawState['challenge'] ?? '')
            : (is_string($rawState) ? $rawState : '');
        $returnUrl = is_array($rawState)
            ? $this->sanitizeReturnUrl($rawState['return_url'] ?? null)
            : $this->sanitizeReturnUrl($request->session()->pull('mobile_return_url'));

        abort_unless(
            $challenge !== '',
            403,
            'Sesi autentikasi Google tidak valid atau telah kedaluwarsa.',
        );

        $profile = $this->googleDriver($request)->user();
        abort_unless($profile instanceof \Laravel\Socialite\Two\User, 403);

        $raw = $profile->getRaw();
        $emailVerified = filter_var($raw['email_verified'] ?? true, FILTER_VALIDATE_BOOLEAN);
        abort_unless($profile->getEmail() && $emailVerified, 403, 'Email Google belum terverifikasi.');

        $email = strtolower(trim((string) $profile->getEmail()));
        $user = User::where('google_id', $profile->getId())->first()
            ?? User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $profile->getName() ?: User::availableUsername($email),
                'email' => $email,
                'password' => Str::random(64),
            ]);
        }

        $user->forceFill([
            'name' => $profile->getName() ?: ($user->name ?: User::availableUsername($email)),
            'google_id' => $profile->getId(),
            'username' => $user->username ?? User::availableUsername($email),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->storeRemoteAvatar($profile->getAvatar());
        $user->ensureServerAvatar();

        $code = Str::random(64);
        Cache::put(
            'mobile-grant:'.$code,
            ['challenge' => $challenge, 'user' => $user->id],
            now()->addMinutes(5),
        );
        Cache::put(
            'mobile-challenge-grant:'.$challenge,
            $code,
            now()->addMinutes(5),
        );

        if ($request->boolean('format_json') || $request->query('format') === 'json' || $request->expectsJson()) {
            return response()->json([
                'grant_code' => $code,
                'email' => $user->email,
                'username' => $user->username,
            ])->header('Cache-Control', 'no-store');
        }

        Auth::login($user);
        $request->session()->regenerate();

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return redirect()->away($returnUrl.$separator.'code='.urlencode($code))->header('Cache-Control', 'no-store');
    }

    public function finish(Request $request): RedirectResponse
    {
        $rawState = $request->session()->pull('mobile_challenge')
            ?? Cache::pull('mobile_latest_challenge');
        $challenge = is_array($rawState)
            ? (string) ($rawState['challenge'] ?? '')
            : (is_string($rawState) ? $rawState : '');
        $returnUrl = is_array($rawState)
            ? $this->sanitizeReturnUrl($rawState['return_url'] ?? null)
            : $this->sanitizeReturnUrl($request->session()->pull('mobile_return_url'));

        abort_unless($challenge !== '', 403);

        /** @var User $user */
        $user = $request->user();
        $code = Str::random(64);
        Cache::put('mobile-grant:'.$code, ['challenge' => $challenge, 'user' => $user->id], now()->addMinutes(2));
        Cache::put('mobile-challenge-grant:'.$challenge, $code, now()->addMinutes(2));

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return redirect()->away($returnUrl.$separator.'code='.urlencode($code))->header('Cache-Control', 'no-store');
    }

    private function sanitizeReturnUrl(?string $raw): string
    {
        $url = trim((string) $raw);
        if ($url === '') {
            return 'gunydrive://auth';
        }

        foreach (['gunydrive://', 'exp://', 'exps://', 'http://localhost:', 'http://127.0.0.1:'] as $allowedPrefix) {
            if (str_starts_with($url, $allowedPrefix)) {
                return $url;
            }
        }

        return 'gunydrive://auth';
    }

    private function resolvedRedirectUri(Request $request): string
    {
        $configuredRedirect = trim((string) config('services.google.redirect', ''));
        if (
            $configuredRedirect !== ''
            && ! str_contains($configuredRedirect, '://localhost')
            && ! str_contains($configuredRedirect, '://127.0.0.1')
        ) {
            return $configuredRedirect;
        }

        $host = $request->getHost();
        if (
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && $host !== '127.0.0.1'
        ) {
            $port = $request->getPort();
            $portSuffix = ($port && ! in_array($port, [80, 443], true)) ? ':'.$port : '';

            return $request->getScheme().'://'.$host.'.sslip.io'.$portSuffix.'/mobile/google/callback';
        }

        if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
            return $request->getSchemeAndHttpHost().'/mobile/google/callback';
        }

        return $configuredRedirect !== ''
            ? $configuredRedirect
            : $request->getSchemeAndHttpHost().'/mobile/google/callback';
    }

    private function googleDriver(Request $request): GoogleProvider
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->redirectUrl($this->resolvedRedirectUri($request))->stateless();
    }
}
