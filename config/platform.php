<?php

/*
 * Platform-level flags that had no config entry and were being read with `env()` from Blade views
 * and from app code (F48 / ledger R2).
 *
 * WHY THEY NEEDED A HOME. `env()` is only safe inside `config/`. Once `php artisan config:cache`
 * runs -- the normal production posture -- Laravel stops loading `.env` on subsequent requests, so
 * every `env()` call elsewhere answers with its default, which for these two was `null`. Both flags
 * were read in two places each (a Blade view and app code), so a cached deployment silently
 * disagreed with its own `.env`.
 *
 * THE ENV NAMES ARE UNCHANGED on purpose. A deployment's existing `.env` keeps working as written;
 * only the read path moves. Renaming them would be a deployment-visible change for no gain.
 *
 * THE DEFAULTS PRESERVE TODAY'S CACHED BEHAVIOUR rather than today's uncached behaviour, which is
 * the conservative direction: on a cached deployment both call sites already saw `null`, so
 * `is_mobile` read as "not mobile" and `default_language` fell back to `'en'` at every site. These
 * defaults reproduce that exactly, and an operator who has set the variable now gets it honoured
 * instead of discarded.
 */

return [

    /*
     * Whether the mobile-app surfaces are offered. Read by `AppSetting/mobile_content.blade.php`
     * (12 sites) and by `app/Helper/helper.php` (:98 and :2550), which hide the
     * `mobilescreensetting` entry when it is not 'yes'. Compared as a STRING against 'yes' at every
     * site, so it stays a string here rather than becoming a bool and changing those comparisons.
     *
     * CAVEAT FOR THE NEXT SITE: `'no'` is TRUTHY, where the `null` it replaces was falsy. All 14
     * current sites compare `== 'yes'` or `!= 'yes'`, so this is invisible today -- but a new site
     * written as `@if (config('platform.is_mobile'))` would read 'no' as ON. Compare against 'yes'.
     */
    'is_mobile' => env('IS_MOBILE', 'no'),

    /*
     * The platform's default language code. Read by `language/index.blade.php` (:8 and :22, twice
     * each) and by `LanguageController::destroyLang()` (:386 -- NOT `changelanguage()`, which is at
     * :85; the pointer matters, because :386 sits in the method that DELETES a language directory),
     * all three of which already fall back to 'en' themselves.
     *
     * THIS KEY DECIDES WHICH LANGUAGE CANNOT BE DELETED. `language/index.blade.php` hides the delete
     * control for whichever language this names. Before F48/R2 a cached deployment read `null` here
     * and therefore always protected 'en'; now it protects whatever `.env` says. A deployment setting
     * `default_language=ar` would, on the view's guard alone, have offered 'en' for deletion -- and
     * deleting it breaks `config('app.fallback_locale')`, which is 'en'. So `destroyLang()` now
     * refuses the default and the fallback locale SERVER-side instead of relying on a hidden button.
     * Found by independent review of this change.
     *
     * NOT `config('app.locale')`. That is the framework's active locale for the current request;
     * this is the platform's configured default, which the language screen compares each installed
     * language against. Collapsing the two would change which language the screen treats as
     * undeletable.
     */
    'default_language' => env('default_language', 'en'),

    /*
     * The product name shown in page titles, the order receipt title and the SMTP test mail.
     *
     * WHY THIS IS NOT `config('app.name')`. Those four view sites read
     * `env('APP_NAME') ?? 'Ecommercego saas'`, so the shipped brand was the fallback whenever
     * APP_NAME was absent -- and on a cached deployment `env()` returned null, so it was the
     * fallback ALWAYS. `config('app.name')` cannot reproduce that: `config/app.php:16` defaults it
     * to 'Laravel', so an absent APP_NAME would have put "Laravel" into a page title, a
     * customer-facing receipt title and a merchant's SMTP test mail. Measured in independent
     * review of this change, which is where it was caught.
     *
     * `?:` rather than a second argument to env(), so an APP_NAME that is PRESENT BUT EMPTY also
     * falls back instead of rendering nothing.
     */
    'brand_name' => env('APP_NAME') ?: 'Ecommercego saas',

];
