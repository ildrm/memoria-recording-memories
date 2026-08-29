<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f8f5ed" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#181a18" media="(prefers-color-scheme: dark)">
    <meta name="description" content="@yield('description', __('A private place for memories, with publishing only when you choose.'))">
    <meta name="robots" content="@yield('robots', 'index,follow')">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @hasSection('canonical')
        <link rel="canonical" href="@yield('canonical')">
    @endif
    <title>@yield('title', config('app.name', 'Memoria'))</title>
    <script>
        (() => {
            const preference = localStorage.getItem('memoria-theme') || 'system';
            const isDark = preference === 'dark' || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.dataset.theme = preference;
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="public-shell">
    <a href="#main-content" class="skip-link">{{ __('Skip to content') }}</a>

    <header class="border-b hairline" aria-label="{{ __('Primary navigation') }}">
        <div class="mx-auto flex min-h-18 w-full max-w-7xl items-center justify-between gap-4 px-5 sm:px-8 lg:px-10">
            <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center gap-2.5 py-2" aria-label="{{ __('Memoria home') }}">
                <x-public.brand-mark class="size-8" />
                <span class="editorial-title text-xl">{{ config('app.name', 'Memoria') }}</span>
            </a>

            <nav class="flex items-center gap-1 sm:gap-3" aria-label="{{ __('Account') }}">
                <button type="button" class="button-quiet size-11 px-0" data-theme-toggle aria-label="{{ __('Change appearance') }}">
                    <svg class="size-5 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.64 13a9 9 0 1 1-10.63-10.63A7 7 0 0 0 21.64 13Z" />
                    </svg>
                    <svg class="hidden size-5 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <circle cx="12" cy="12" r="4" />
                        <path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" />
                    </svg>
                    <span class="sr-only" data-theme-label>{{ __('Change appearance') }}</span>
                </button>
                @auth
                    <a href="{{ url('/app') }}" class="button-secondary">{{ __('Open my diary') }}</a>
                @else
                    <a href="{{ url('/app/login') }}" class="button-quiet hidden sm:inline-flex">{{ __('Sign in') }}</a>
                    <a href="{{ url('/app/register') }}" class="button-primary">{{ __('Start writing') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main-content" tabindex="-1">
        @if (session('status'))
            <div class="mx-auto w-full max-w-7xl px-5 pt-6 sm:px-8 lg:px-10" role="status" aria-live="polite">
                <div class="paper-surface border-s-4 border-s-[var(--accent)] px-4 py-3 text-sm leading-6">
                    {{ session('status') }}
                </div>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="border-t hairline">
        <div class="mx-auto grid w-full max-w-7xl gap-8 px-5 py-12 sm:px-8 md:grid-cols-[1fr_auto] md:items-end lg:px-10">
            <div class="max-w-xl">
                <div class="flex items-center gap-2.5">
                    <x-public.brand-mark class="size-7" />
                    <span class="editorial-title text-lg">{{ config('app.name', 'Memoria') }}</span>
                </div>
                <p class="muted-copy mt-3 text-sm leading-6">{{ __('Your private memories stay private. Public stories begin only with your deliberate choice.') }}</p>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm muted-copy">
                @if (Route::has('privacy'))
                    <a href="{{ route('privacy') }}" class="hover:text-[var(--ink)]">{{ __('Privacy') }}</a>
                @endif
                @if (Route::has('terms'))
                    <a href="{{ route('terms') }}" class="hover:text-[var(--ink)]">{{ __('Terms') }}</a>
                @endif
                <span>&copy; {{ now()->year }} {{ config('app.name', 'Memoria') }}</span>
            </div>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
