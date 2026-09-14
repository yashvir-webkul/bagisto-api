<?php

namespace Webkul\BagistoApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleChannel
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $channel = core()->getCurrentChannel();
        if (! $channel) {
            return $next($request);
        }

        $channelCode = $request->header('X-Channel');

        if ($channelCode) {
            $request->attributes->set('bagisto_channel', $channelCode);
        }

        $locale = $request->header('X-Locale');
        $availableLocales = $channel->locales ? $channel->locales->pluck('code')->toArray() : [];
        $defaultLocale = $channel->default_locale?->code;

        if ($locale && in_array($locale, $availableLocales)) {
            app()->setLocale($locale);
            $request->attributes->set('bagisto_locale', $locale);
        } elseif ($defaultLocale) {
            app()->setLocale($defaultLocale);
            $request->attributes->set('bagisto_locale', $defaultLocale);
        }

        $currency = $request->header('X-Currency');
        $availableCurrencies = $channel->currencies ? $channel->currencies->pluck('code')->toArray() : [];
        $defaultCurrency = $channel->base_currency?->code;

        if ($currency && in_array($currency, $availableCurrencies)) {
            core()->setCurrentCurrency($currency);
            $request->attributes->set('bagisto_currency', $currency);
        } elseif ($defaultCurrency) {
            core()->setCurrentCurrency($defaultCurrency);
            $request->attributes->set('bagisto_currency', $defaultCurrency);
        }

        return $next($request);
    }
}
