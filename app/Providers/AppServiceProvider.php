<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $public = config('gunydrive.public_path');
        if (is_string($public) && $public !== '') {
            $this->app->usePublicPath($public);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        VerifyEmail::toMailUsing(function (User $user, string $url) {
            $themes = config('mail-themes');
            $theme = request()->input('theme');
            $scheme = request()->input('scheme') === 'dark' ? 'dark' : 'light';
            $colors = $themes[is_string($theme) && isset($themes[$theme]) ? $theme : 'kaca'][$scheme];
            $code = $user->issueEmailVerificationCode();

            return (new MailMessage)
                ->subject('Kode & Tautan Verifikasi Email GunyDrive')
                ->view(['mail.verify-email', 'mail.verify-email-text'], [
                    'colors' => $colors,
                    'name' => $user->name,
                    'url' => $url,
                    'code' => $code,
                    'minutes' => config('auth.verification.expire', 60),
                ]);
        });

        Gate::define('manage-server', fn (User $user): bool => (bool) $user->is_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
