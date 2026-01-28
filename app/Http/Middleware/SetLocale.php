<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Supported locales
     */
    protected array $supportedLocales = ['en', 'es'];

    /**
     * Default locale
     */
    protected string $defaultLocale = 'es';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->detectLocale($request);
        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * Detect locale from authenticated user or fallback
     */
    protected function detectLocale(Request $request): string
    {
        // 1. Check authenticated user's locale preference
        $user = $request->user();

        if ($user && $user->locale && in_array($user->locale, $this->supportedLocales)) {
            return $user->locale;
        }

        // 2. Check custom header X-Locale (fallback for non-authenticated requests)
        if ($request->hasHeader('X-Locale')) {
            $locale = $request->header('X-Locale');
            if (in_array($locale, $this->supportedLocales)) {
                return $locale;
            }
        }

        // 3. Check Accept-Language header
        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            $locale = substr($acceptLanguage, 0, 2);
            if (in_array($locale, $this->supportedLocales)) {
                return $locale;
            }
        }

        // 4. Default locale
        return $this->defaultLocale;
    }
}
