<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use DateTimeZone;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserPreferences
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $previousLocale = App::getLocale();
        $previousTimezone = FilamentTimezone::get();
        $preferences = $user->preferences()->first(['locale', 'timezone']);
        $supportedLocales = array_keys((array) config('memoria.localization.supported_locales', ['en' => 'English']));
        $configuredLocale = (string) config('app.locale', 'en');
        $fallbackLocale = in_array($configuredLocale, $supportedLocales, true)
            ? $configuredLocale
            : ($supportedLocales[0] ?? 'en');
        $locale = is_string($preferences?->locale) && in_array($preferences->locale, $supportedLocales, true)
            ? $preferences->locale
            : $fallbackLocale;
        $timezone = is_string($preferences?->timezone) && in_array($preferences->timezone, DateTimeZone::listIdentifiers(), true)
            ? $preferences->timezone
            : (string) config('app.timezone', 'UTC');

        App::setLocale($locale);
        FilamentTimezone::set($timezone);

        try {
            return $next($request);
        } finally {
            App::setLocale($previousLocale);
            FilamentTimezone::set($previousTimezone);
        }
    }
}
