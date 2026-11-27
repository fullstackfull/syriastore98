<?php

namespace App\Support\Help;

use App\Support\Docs\DocumentationLibrary;

/**
 * PHASE 06 `SUPPORT-002` — «مساعدة سياقية» : THE HELP FOR THE SCREEN THE MERCHANT IS ON.
 * =================================================================================================
 *
 * `SUPPORT-002` is *"مساعدة سياقية وإعلانات للويب وFlutter"* — contextual help and announcements,
 * for the web **and** Flutter (`DECISION-ID-REGISTER.md` §2, the SUPPORT table, row 2). This class
 * is the first clause. `MerchantAnnouncements` is the second.
 *
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` recorded the requirement as **NOT IMPLEMENTED** on both
 * clauses and both clients, and its gap (b) says the owner has not settled "what *contextual* is to
 * mean — the recovered text is one line and does not say". So what is built here is the MECHANISM
 * and not the copy: a surface, the help for that surface, the reader's language, and the same answer
 * on both clients. **No help prose is invented anywhere in this class.**
 *
 * -------------------------------------------------------------------------------------------------
 * THERE IS NO SECOND STORE OF HELP TEXT. THAT IS THE DECISION THIS CLASS EXISTS TO MAKE.
 *
 * `App\Support\Docs\DocumentationLibrary` is already the owner's bilingual documentation centre:
 * markdown in `resources/docs/{en,ar}/<category>/<article>.md`, a published/draft state, a locale
 * fallback that is deliberately visible rather than silent, and a cached index. Its own docblock
 * records why the prose is in the repository rather than a table — reviewable in a diff, searchable
 * in an editor, assertable by a test, and a bilingual pair visible as two files side by side.
 *
 * Every one of those reasons applies at least as strongly to contextual help, which is the SAME
 * prose read from a different entry point. A second corpus would be a second thing to keep
 * bilingual, a second thing to review, a second thing to migrate — and `ARCH-002` forbids a
 * parallel engine. So contextual help is a MAP, and the map is the only new content:
 *
 *     surface key  ->  documentation article ids
 *
 * A map can fail in exactly one way — an id that names nothing — and `ContextualHelpTest`'s first
 * case asserts every id resolves **per locale**, because the library's fallback would otherwise
 * serve English to an Arabic reader and a one-locale assertion would photograph that as working.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SURFACE VOCABULARY IS CLIENT-NEUTRAL, AND THAT IS NOT TIDINESS.
 *
 * The web resolves a surface from the Laravel route name. The Flutter app has no Laravel route
 * names and sends the surface key itself over `GET api/admin/help-topics`. If the keys WERE route
 * names, the shipped app's contract would be coupled to this repository's routing table and a route
 * rename — which happens, and which no test on the app side could see — would silently empty the
 * help on that screen. The keys are therefore short lowercase nouns, asserted not to collide with
 * any registered route name.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ROUTE MATCH IS ON THE WHOLE FIRST SEGMENT, NEVER `str_starts_with`.
 *
 * `plan-coupon.index` is the PLATFORM OPERATOR's screen and `str_starts_with($name, 'plan')` is true
 * of it, so a prefix match would hang the merchant's plan-limits help off an operator surface.
 * `product-attribute-option.create` and `order-note.store` are the same shape. The first dot
 * segment is compared whole; `ContextualHelpTest` drives all three.
 *
 * -------------------------------------------------------------------------------------------------
 * A SURFACE KEY IS CALLER-SUPPLIED ON THE FLUTTER PATH AND IS NEVER PART OF A PATH.
 *
 * It is a key lookup in `SURFACES` and nothing else. No concatenation, no `base_path()`, no
 * `file_get_contents`. An unknown key answers `[]`. `DocumentationLibrary::article()` is itself a
 * lookup in a pre-built in-memory index rather than a filesystem read, so even the ids this class
 * passes it cannot reach the disk. The hostile shapes (`../../etc/passwd`, `products/../orders`) are
 * driven in the test rather than argued here.
 *
 * NOTHING THIS CLASS RETURNS IS TENANT DATA. Articles are repository files and the map is a
 * constant, so there is no store, no customer and no order anywhere in the payload — which is the
 * whole answer to "a merchant-facing help panel must not leak another tenant's anything". It is
 * asserted rather than asserted-by-inspection: `MerchantHelpApiTest` reads the same surface as two
 * different merchants and requires byte-identical payloads.
 */
final class ContextualHelp
{
    /**
     * SURFACE KEY => DOCUMENTATION ARTICLE IDS, in the order a merchant should read them.
     *
     * An article id is a `category/slug` key of `DocumentationLibrary::published()`. The slugs have
     * their numeric file prefixes stripped by the library's own parser, so it is
     * `products/add-a-product` and not `03-products/01-add-a-product`.
     *
     * EVERY SURFACE HERE HAS AN ARTICLE IN BOTH LANGUAGES ALREADY. Surfaces the corpus cannot yet
     * answer — customers, coupons, reports, the wallet — are deliberately ABSENT rather than
     * present-and-empty: a help affordance that opens on nothing is worse than one that is not
     * offered, and adding the surface would require writing the articles, which is owner copy.
     * Adding a row here once an article exists is a one-line change and lights up every screen on
     * that route segment with no view edit at all.
     */
    public const SURFACES = [
        /* `overview` AND NOT `dashboard`, WHICH IS A REAL FINDING RATHER THAN A PREFERENCE.
           `dashboard` is a registered Laravel route name — with no dot, so it is its own first
           segment — and `surface_keys_are_not_laravel_route_names` reddened on exactly that. The
           key the shipped app compiles in must not be a name this repository's routing table owns,
           because renaming the route would then be a silent change to the app's contract. */
        'overview' => [
            'getting-started/create-your-first-store',
            'getting-started/choose-a-plan',
        ],
        'products' => [
            'products/add-a-product',
            'products/variants-and-options',
            'products/stock-and-availability',
            'products/import-from-a-file',
        ],
        'orders' => [
            'orders/how-an-order-moves',
            'orders/payment-and-delivery',
        ],
        'storefront' => [
            'your-storefront/choose-a-theme',
            'your-storefront/edit-your-theme',
            'your-storefront/preview-and-publish',
            'your-storefront/restore-an-earlier-version',
        ],
        'staff' => [
            'your-account/staff-and-what-they-can-do',
            'your-account/keeping-your-account-safe',
        ],
        'plan' => [
            'your-account/plan-limits',
            'getting-started/choose-a-plan',
        ],
        'account' => [
            'getting-started/create-your-account',
            'your-account/keeping-your-account-safe',
        ],
    ];

    /**
     * THE WEB'S HALF: the FIRST DOT SEGMENT of a Laravel route name => a surface key.
     *
     * Compared whole. See the class docblock for the operator screen a prefix match would claim.
     *
     * @return array<string, string>
     */
    public static function routeSegments(): array
    {
        return [
            'dashboard' => 'overview',
            'product' => 'products',
            'order' => 'orders',
            'theme' => 'storefront',
            'users' => 'staff',
            /* BOTH halves of staff management reach the same two guides: `users.*` is who the staff
               are and `roles.*` is what they may do. The segment is `roles` and not `role` --
               measured against the router, and `every_mapped_route_segment_names_at_least_one_
               registered_route` is what will say so if either is ever renamed. */
            'roles' => 'staff',
            'plan' => 'plan',
            'profile' => 'account',
        ];
    }

    /**
     * The surface key for a Laravel route name, or null when that screen has no help.
     *
     * Null rather than an empty array, so the caller can tell "this screen has no mapped surface"
     * from "this surface's articles did not resolve" — the first is expected on most of the 334
     * merchant screens and the second is a defect this class's test would have caught.
     */
    public static function surfaceForRoute(?string $routeName): ?string
    {
        $routeName = trim((string) $routeName);

        if ($routeName === '') {
            return null;
        }

        $segment = explode('.', $routeName)[0];

        return self::routeSegments()[$segment] ?? null;
    }

    /**
     * The resolved help for a surface, in the reader's language.
     *
     * @return list<array<string, mixed>>  [] for an unknown surface — see the class docblock
     */
    public static function articlesForSurface(string $surface, ?string $locale = null): array
    {
        $ids = self::SURFACES[$surface] ?? null;

        if (! is_array($ids)) {
            return [];
        }

        /*
         * TWO LOCALES, AND THE SECOND ONE IS WHY `is_fallback` MEANS ANYTHING.
         *
         * `$requested` is what the centre can actually be asked for — `ar` or `en`. `$asked` is the
         * language the reader asked for, which can be neither: the panel ships several locales and
         * the Flutter app sends the device's. THE FIRST DRAFT COMPARED `$served` TO `$requested`
         * AND `an_unshipped_locale_falls_back_and_says_so` CAUGHT IT: a French reader was served
         * English with `is_fallback` FALSE, because `normaliseLocale('fr')` had already folded the
         * question away. Two substitutions have to be reported, not one — the centre not shipping
         * the reader's language at all, and this particular article missing in it.
         */
        $requested = self::normaliseLocale($locale);
        $asked     = self::askedLocale($locale);
        $resolved  = [];

        foreach ($ids as $id) {
            [$category, $slug] = array_pad(explode('/', $id, 2), 2, '');

            $article = DocumentationLibrary::article($requested, $category, $slug);

            if ($article === null) {
                /* The id names nothing in ANY language. `ContextualHelpTest` reddens on this, so it
                   cannot reach a merchant — but if it ever does, a missing article must not be a
                   dead link in a help panel. */
                continue;
            }

            $served = (string) ($article['locale'] ?? $requested);

            $resolved[] = [
                'id' => $id,
                'title' => (string) ($article['title'] ?? ''),
                'summary' => (string) ($article['summary'] ?? ''),
                'url' => route('platform.docs.article', [
                    'category' => $category,
                    'article' => $slug,
                ]),
                'locale' => $served,
                /* THE SUBSTITUTION IS REPORTED, NOT SWALLOWED. The library serves the other
                   language when the reader's is missing, which is right; a reader who is not told
                   is the failure. Both the panel and the app print a notice from this flag. */
                'is_fallback' => $served !== $asked,
            ];
        }

        return $resolved;
    }

    /**
     * The resolved help for whatever screen a Laravel route name names.
     *
     * @return list<array<string, mixed>>
     */
    public static function articlesForRoute(?string $routeName, ?string $locale = null): array
    {
        $surface = self::surfaceForRoute($routeName);

        return $surface === null ? [] : self::articlesForSurface($surface, $locale);
    }

    /**
     * THE WHOLE MAP, RESOLVED, in declaration order — one call for the Flutter app to cache.
     *
     * The app asks once and keeps it: help for a screen it is already on must not need a round
     * trip, and the corpus changes on a deploy rather than per request.
     *
     * @return list<array<string, mixed>>
     */
    public static function vocabulary(?string $locale = null): array
    {
        $out = [];

        foreach (array_keys(self::SURFACES) as $surface) {
            $out[] = [
                'surface' => $surface,
                'articles' => self::articlesForSurface($surface, $locale),
            ];
        }

        return $out;
    }

    /**
     * `en` or `ar` — a locale the documentation centre actually ships.
     *
     * The panel's locale can be any of the several in `resources/lang/`, and the Flutter app sends
     * the language the merchant chose on the device. Either can be something the centre does not
     * ship, which `DocumentationLibrary::resolveLocale()` already folds to its first locale; this
     * also accepts a regional tag (`ar-SY`, `ar_SA`, `en-GB`) because a device locale usually
     * carries one, and `DocumentationLibrary` alone would fold `ar-SY` to English.
     */
    public static function normaliseLocale(?string $locale = null): string
    {
        return DocumentationLibrary::resolveLocale(self::askedLocale($locale));
    }

    /**
     * The language the reader ASKED for, stripped of its region but NOT folded to a shipped one.
     *
     * `ar-SY` and `ar_SA` are `ar`; `fr` stays `fr`. The difference between this and
     * `normaliseLocale()` is the whole basis of the `is_fallback` flag — see `articlesForSurface()`.
     */
    private static function askedLocale(?string $locale = null): string
    {
        $locale = strtolower(trim((string) ($locale ?? app()->getLocale())));

        return explode('-', str_replace('_', '-', $locale))[0];
    }
}
