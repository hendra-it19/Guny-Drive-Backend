<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ $dashboardTheme ?? 'kaca' }}"
    @class(['dark' => ($appearance ?? 'system') == 'dark'])
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode and dashboard theme preference immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';
                const theme = localStorage.getItem('dashboard_theme') || '{{ $dashboardTheme ?? "kaca" }}';

                document.documentElement.setAttribute('data-theme', theme);

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        <style>
            html {
                background-color: #EEF1FB;
            }

            html[data-theme="minimal"] {
                background-color: #F6F7F9;
            }

            html.dark {
                background-color: #080B1A;
            }

            html.dark[data-theme="minimal"] {
                background-color: #0B0C0E;
            }

            #gd-boot-splash {
                position: fixed;
                inset: 0;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 14px;
                background-color: #EEF1FB;
                background-image:
                    radial-gradient(360px 260px at 14% 8%, rgba(142, 162, 255, 0.45), transparent 65%),
                    radial-gradient(340px 260px at 88% 18%, rgba(195, 166, 255, 0.40), transparent 65%),
                    radial-gradient(360px 280px at 50% 92%, rgba(127, 216, 245, 0.35), transparent 70%);
                color: #0E1530;
                transition: opacity 220ms ease, visibility 220ms ease;
            }

            html.dark #gd-boot-splash {
                background-color: #080B1A;
                background-image:
                    radial-gradient(360px 260px at 14% 8%, rgba(59, 63, 216, 0.38), transparent 65%),
                    radial-gradient(340px 260px at 88% 18%, rgba(122, 63, 224, 0.32), transparent 65%),
                    radial-gradient(360px 280px at 50% 92%, rgba(14, 143, 184, 0.28), transparent 70%);
                color: #EEF1FF;
            }

            #gd-boot-splash.gd-splash-hidden {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
            }

            .gd-boot-badge {
                width: 68px;
                height: 68px;
                border-radius: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, #4F46E5 0%, #8B5CF6 55%, #22B8E0 100%);
                box-shadow:
                    0 14px 32px -8px rgba(79, 70, 229, 0.42),
                    inset 0 1px 0 rgba(255, 255, 255, 0.45);
                border: 1px solid rgba(255, 255, 255, 0.35);
            }
        </style>

        <meta name="theme-color" content="#EEF1FB" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#080B1A" media="(prefers-color-scheme: dark)">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'GunyDrive') }}">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'GunyDrive') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <div id="gd-boot-splash" aria-hidden="true">
            <div class="gd-boot-badge">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none">
                    <path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4.5 4.5 0 0 1-.5 9.5Z" fill="#FFFFFF" fill-opacity="0.16" />
                    <path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4.5 4.5 0 0 1-.5 9.5Z" stroke="#FFFFFF" stroke-width="2.15" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
        </div>
        <x-inertia::app />
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                requestAnimationFrame(function () {
                    var splash = document.getElementById('gd-boot-splash');
                    if (splash) {
                        splash.classList.add('gd-splash-hidden');
                        setTimeout(function () {
                            splash.remove();
                        }, 260);
                    }
                });
            });
        </script>
    </body>
</html>
