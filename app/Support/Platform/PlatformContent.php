<?php

namespace App\Support\Platform;

/**
 * GAP-104 (P03-C-05) — WHAT AN OPERATOR MAY CHANGE ON THE OFFICIAL STORE SYRIA WEBSITE.
 * =================================================================================================
 *
 * The gap this discharges, in its own words: "all seven pages are Blade templates under
 * resources/views/platform/ with literal copy wrapped in __()… Changing a headline, an FAQ answer
 * or the legal text requires a code change, review, merge and deploy. There is no admin screen for
 * platform content." Plus the knock-on it singles out: "the five legal documents and the contact
 * details, which the owner must supply, can only be published by a developer."
 *
 * THE SPLIT THIS CLASS EXISTS TO HOLD. The CAPABILITY is engineering's; the CONTENT is the owner's.
 * So every field below is in exactly one of two states and the difference is declared, not implied:
 *
 *   A FIELD WITH A `default` carries the copy the site ships with — the literal that is in the
 *     Blade view today, used as its translation key so that an un-overridden page renders byte for
 *     byte what it renders now, in both languages. An operator may replace it and may clear the
 *     replacement to get it back.
 *
 *   A FIELD WITH `default => null` IS OWED BY THE OWNER. The five legal documents and the four
 *     contact details are in this state. They resolve to the EMPTY STRING, the pages keep saying
 *     the text is not published, and nothing in this repository invents a privacy notice, a postal
 *     address or a telephone number. Showing that content is owed is the capability; filling it in
 *     would be a false statement about a real business.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY A `default` IS A TRANSLATION KEY AND NOT A SENTENCE OF ITS OWN.
 *
 * Every key in this application IS its English sentence and `__()` returns its argument on a miss,
 * so a default that names a key `ar.json` does not carry puts English on the Arabic page and
 * nothing fails — the exact failure mode CLAUDE.md records for bilingual assertions.
 * `PlatformContentRegistryTest::test_every_default_is_a_key_both_language_files_carry` reads both
 * JSON files and fails on it, which is the only check that can catch a typo here.
 *
 * WHY THERE IS NO CROSS-LOCALE FALLBACK FOR AN OWED FIELD. An approved Arabic privacy notice and an
 * approved English one are two documents, not one document in two spellings; serving the English
 * text to an Arabic reader because the Arabic is outstanding would publish an unapproved
 * translation of a binding document. So each locale publishes only what was supplied for it, the
 * editor shows the per-locale state, and an Arabic reader of an English-only document is told it is
 * not published yet. The alternative was considered and is named here rather than left as a silent
 * difference.
 *
 * WHAT IS DELIBERATELY NOT EDITABLE, and this list is part of the design rather than an omission:
 *
 *   - PRICES AND PLAN LIMITS. They come from the `plans` table the subscription flow charges
 *     against (`PlatformSiteController::publishedPlans()`), and M5's standing rule is that the site
 *     must not be able to state a price the product does not charge. Making the figures editable
 *     would reintroduce exactly the drift that rule exists to prevent. The pricing NARRATIVE — the
 *     lede and the note that explains what the figures mean — is editable; the figures are not.
 *   - CAPABILITY CLAIMS. `App\Support\Platform\PlatformCapabilities` anchors each of 34 claims to
 *     a module on disk or a theme in `themes/`, and `P03-C-04` is VERIFIED COMPLETE on the strength
 *     of that anchoring. A free-text override would unanchor it and let the site promise what the
 *     product cannot do.
 *   - PAGE STRUCTURE, SECTIONS AND IMAGES. This is a content capability, not a page builder. M5's
 *     constraint — "do not create a second landing-page engine" — still holds, and the WorkDo
 *     LandingPage module remains the builder for `/`.
 *   - THE LEGAL DOCUMENTS' TITLES AND SUMMARIES. They describe what each document will cover and
 *     are structural; the BINDING TEXT is the editable field. A title that could drift from the
 *     document beneath it buys nothing.
 */
final class PlatformContent
{
    /**
     * The languages the official site is published in, and therefore the only two an override may
     * be stored for. Any other application locale falls through to `__()`, which is the behaviour
     * the site has today.
     */
    public const LOCALES = ['en', 'ar'];

    /**
     * `line`     one line, rendered escaped (a heading, an eyebrow, a telephone number)
     * `block`    several lines of plain text, rendered escaped with newlines preserved
     * `markdown` Markdown, rendered by the documentation centre's renderer — raw HTML STRIPPED and
     *            unsafe links refused, so an operator cannot put script into a public page
     */
    public const KINDS = ['line', 'block', 'markdown'];

    /** Caps, per kind. A `longText` column will take far more; a public page should not. */
    private const MAX_LENGTH = [
        'line'     => 300,
        'block'    => 4000,
        'markdown' => 60000,
    ];

    /** The five legal documents, in the order the public page lists them. */
    public const LEGAL_DOCUMENTS = [
        'terms'           => 'Terms of service',
        'privacy'         => 'Privacy notice',
        'acceptable-use'  => 'Acceptable use',
        'refunds'         => 'Subscription, billing and refunds',
        'data-processing' => 'Handling your customers\' data',
    ];

    /** How many question/answer pairs the FAQ page carries. */
    public const FAQ_COUNT = 10;

    /**
     * The seven public pages, each with the route a reviewer uses to see their change.
     *
     * @return array<string, array{label: string, route: string}>
     */
    public static function pages(): array
    {
        return [
            'home'        => ['label' => 'Overview',          'route' => 'platform.home'],
            'features'    => ['label' => 'Features',          'route' => 'platform.features'],
            'pricing'     => ['label' => 'Pricing',           'route' => 'platform.pricing'],
            'onboarding'  => ['label' => 'Getting started',   'route' => 'platform.onboarding'],
            'faq'         => ['label' => 'FAQ',               'route' => 'platform.faq'],
            'contact'     => ['label' => 'Contact',           'route' => 'platform.contact'],
            'legal'       => ['label' => 'Legal information', 'route' => 'platform.legal'],
        ];
    }

    /**
     * Every editable field.
     *
     * @return array<string, array{page: string, kind: string, label: string, default: string|null}>
     */
    public static function fields(): array
    {
        static $fields = null;

        if ($fields !== null) {
            return $fields;
        }

        $fields = [];

        /*
         * THE HERO OF EACH PAGE. Three strings — eyebrow, heading, intro — which between them are
         * the marketing copy a visitor reads first and the copy most likely to need changing
         * without a release. The defaults are the literals in each view today, so an
         * un-overridden page is unchanged.
         */
        $heroes = [
            'home' => [
                'Commerce platform',
                'Open your shop online, and run the whole business from one place.',
                'Store Syria gives a merchant three things that work together: a public shop with its own address and theme, a dashboard for products, orders and customers, and an admin app for the phone. Arabic and English throughout, right to left where it should be.',
            ],
            'features' => [
                'Features',
                'What the platform does',
                'Everything listed below is part of the platform. Which parts a particular account can switch on depends on its plan, so check the plan before you count on one of them.',
            ],
            'pricing' => [
                'Pricing',
                'Plans',
                'Each plan is shown with the limits it carries. These are the figures the platform itself applies to an account, not a summary of them.',
            ],
            'onboarding' => [
                'Getting started',
                'From signing up to a shop that is open',
                'There are six steps, and none of them needs a developer.',
            ],
            'faq' => [
                'FAQ',
                'Questions people ask',
                'If your question is not here, the contact page will reach us.',
            ],
            'contact' => [
                'Contact',
                'Talk to us',
                'About a plan before you subscribe, about your account once you have one, or about something that is not working.',
            ],
            'legal' => [
                'Legal',
                'Legal information',
                'These are the documents that will govern using Store Syria.',
            ],
        ];

        foreach ($heroes as $page => [$eyebrow, $title, $lede]) {
            $fields[$page . '.hero.eyebrow'] = [
                'page' => $page, 'kind' => 'line', 'label' => 'Eyebrow', 'default' => $eyebrow,
            ];
            $fields[$page . '.hero.title'] = [
                'page' => $page, 'kind' => 'line', 'label' => 'Heading', 'default' => $title,
            ];
            $fields[$page . '.hero.lede'] = [
                'page' => $page, 'kind' => 'block', 'label' => 'Intro', 'default' => $lede,
            ];
        }

        /* THE PRICING NARRATIVE, which GAP-104 names. The figures are NOT editable; the sentence
           that explains what they mean is. */
        $fields['pricing.note'] = [
            'page'    => 'pricing',
            'kind'    => 'block',
            'label'   => 'Note',
            'default' => 'Storage is shown in megabytes. “Unlimited” means the plan sets no ceiling at all; a figure means that is the ceiling. Taxes and payment-provider charges are not part of these prices.',
        ];

        /* THE FAQ ENTRIES, which GAP-104 names. The defaults are the ten pairs the view carries
           today, moved here so that the registry owns the built-in copy and the view renders
           whatever the registry resolves. */
        foreach (self::faqDefaults() as $i => [$question, $answer]) {
            $n = $i + 1;

            $fields['faq.' . $n . '.question'] = [
                'page' => 'faq', 'kind' => 'line', 'label' => 'Question', 'ordinal' => $n,
                'default' => $question,
            ];
            $fields['faq.' . $n . '.answer'] = [
                'page' => 'faq', 'kind' => 'block', 'label' => 'Answer', 'ordinal' => $n,
                'default' => $answer,
            ];
        }

        /*
         * OWED BY THE OWNER — THE CONTACT DETAILS. A sweep of the settings keys this application
         * reads found `contactus_url` and nothing else: there is no shipped setting for a postal
         * address, a telephone number, a support mailbox or opening hours, which is why the contact
         * page states that the published details are being confirmed. These four fields are where
         * the owner supplies them. Until they do, each resolves to '' and the page is unchanged.
         */
        foreach ([
            'address'   => ['label' => 'Address',       'kind' => 'block'],
            'telephone' => ['label' => 'Telephone',     'kind' => 'line'],
            /* `Email` RATHER THAN `Email address`: the key already exists in both language files,
               and the contact page's own label uses the same one. Two spellings of one field would
               be two translation items for no gain. */
            'email'     => ['label' => 'Email',         'kind' => 'line'],
            'hours'     => ['label' => 'Opening hours', 'kind' => 'block'],
        ] as $name => $meta) {
            $fields['contact.' . $name] = [
                'page'    => 'contact',
                'kind'    => $meta['kind'],
                'label'   => $meta['label'],
                'default' => null,
                'owed'    => true,
            ];
        }

        /*
         * OWED BY THE OWNER — THE FIVE LEGAL DOCUMENTS. The owner's standing restrictions reserve
         * "unapproved changes to legal or commercial policy", and terms of service bind a business
         * from the moment they are published. So the binding text is a field the owner fills, never
         * a draft engineering writes. Markdown, rendered through the documentation centre's
         * renderer: raw HTML is STRIPPED and unsafe links refused.
         */
        foreach (self::LEGAL_DOCUMENTS as $id => $title) {
            $fields['legal.' . $id . '.text'] = [
                'page'    => 'legal',
                'kind'    => 'markdown',
                'label'   => $title,
                'default' => null,
                'owed'    => true,
            ];
        }

        return $fields;
    }

    /**
     * The ten FAQ pairs the site ships with, as translation keys.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function faqDefaults(): array
    {
        return [
            [
                'What is Store Syria?',
                'A platform for running an online shop. You get a public storefront with its own address and theme, a dashboard for products, orders and customers, and an admin app for the phone — all on one account.',
            ],
            [
                'Do I need to know how to build a website?',
                'No. You choose a theme and change its sections, text, images and colours from the dashboard. Nothing needs to be installed or written.',
            ],
            [
                'Can I run more than one shop?',
                'Yes, if your plan allows it. Each store keeps its own products, orders, customers and theme, and you switch between them from the same sign-in. The number of stores a plan permits is shown on the pricing page.',
            ],
            [
                'Is the platform available in Arabic?',
                'Yes. Both the shop and the dashboard are available in Arabic and in English, and the whole layout flips to right-to-left in Arabic. It is the same installation in either language, not two separate ones.',
            ],
            [
                'Will my changes to a theme appear immediately?',
                'Only when you publish them. Editing a theme saves a draft that nobody but you can see; you preview it privately, publish when you are ready, and an earlier version can be restored afterwards.',
            ],
            [
                'Can my staff have their own accounts?',
                'Yes. You create roles, decide what each role may see and change, and give each colleague their own sign-in rather than sharing yours.',
            ],
            [
                'Can another merchant on the platform see my products or orders?',
                'No. Each store\'s catalogue, orders and customers are reachable only from that store.',
            ],
            [
                'What happens to my data if I stop subscribing?',
                'The terms that govern this are being prepared and are not published yet. Until they are, ask us directly rather than relying on an answer here.',
            ],
            [
                'Which payment methods can my shop accept?',
                'That depends on the payment providers configured for your installation and on your plan. Ask before you subscribe if a particular method matters to you.',
            ],
            [
                'How do I get help?',
                'Through the contact page, and from inside the dashboard once you are signed in.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>> the fields of one page, in registry order
     */
    public static function fieldsFor(string $page): array
    {
        return array_filter(
            self::fields(),
            static fn (array $field) => $field['page'] === $page
        );
    }

    /** @return array<string, array<string, mixed>> the fields whose content the OWNER owes */
    public static function owedFields(): array
    {
        return array_filter(
            self::fields(),
            static fn (array $field) => ($field['owed'] ?? false) === true
        );
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::fields());
    }

    /**
     * The text to render for the CURRENT locale: the operator's override, else the built-in copy,
     * else '' when the content is owed.
     */
    public static function text(string $key): string
    {
        $fields = self::fields();

        if (! isset($fields[$key])) {
            return '';
        }

        $override = PlatformContentStore::value(self::locale(), $key);

        if ($override !== null) {
            return $override;
        }

        $default = $fields[$key]['default'];

        /* `__()` with no locale argument, so an un-overridden field renders byte for byte what the
           view rendered before this capability existed. */
        return $default === null ? '' : __($default);
    }

    /**
     * One page's resolved text, ready for a view.
     *
     * THE VIEWS TAKE A RESOLVED ARRAY RATHER THAN CALLING THIS CLASS, so that the resolution — and
     * therefore the override/default/owed decision — happens in exactly one place that a test can
     * drive, and so that a Blade template stays a template. An un-overridden page renders byte for
     * byte what it rendered before this capability existed, because `text()` falls back to `__()`
     * on the same key the literal used to be.
     *
     * @return array<string, string>
     */
    public static function resolved(string $page): array
    {
        $out = [];

        foreach (array_keys(self::fieldsFor($page)) as $key) {
            $out[$key] = self::text($key);
        }

        return $out;
    }

    /** Has the operator supplied this field in this locale? Used by the editor's state column. */
    public static function isSupplied(string $locale, string $key): bool
    {
        return PlatformContentStore::value($locale, $key) !== null;
    }

    /** The operator's stored value for the editor's form, or '' when nothing is stored. */
    public static function stored(string $locale, string $key): string
    {
        return PlatformContentStore::value($locale, $key) ?? '';
    }

    /** The built-in copy in one locale, for the editor to show beside the field. */
    public static function builtIn(string $locale, string $key): string
    {
        $default = self::fields()[$key]['default'] ?? null;

        return $default === null ? '' : __($default, [], $locale);
    }

    /**
     * The FAQ as the page renders it: resolved pairs, with an entry dropped only when BOTH halves
     * resolve empty — which cannot happen today, because every pair carries a default.
     *
     * @return list<array{q: string, a: string}>
     */
    public static function faqs(): array
    {
        $out = [];

        for ($n = 1; $n <= self::FAQ_COUNT; $n++) {
            $q = self::text('faq.' . $n . '.question');
            $a = self::text('faq.' . $n . '.answer');

            if ($q === '' && $a === '') {
                continue;
            }

            $out[] = ['q' => $q, 'a' => $a];
        }

        return $out;
    }

    /**
     * Normalise one submitted value.
     *
     * `\r` is dropped so a Windows browser's CRLF cannot make a saved value differ from the same
     * text typed elsewhere; a `line` field is collapsed to one line because the view renders it
     * inside a heading; and every kind is capped, because this is a public page and a `longText`
     * column will accept megabytes.
     */
    public static function sanitise(string $key, string $value): string
    {
        $kind = self::fields()[$key]['kind'] ?? 'line';

        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\r", "\n", $value);

        if ($kind === 'line') {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        $value = trim($value);

        $max = self::MAX_LENGTH[$kind] ?? 300;

        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    public static function maxLength(string $kind): int
    {
        return self::MAX_LENGTH[$kind] ?? 300;
    }

    /**
     * The current locale, narrowed to the two the official site is published in.
     *
     * An installation running in a third language gets `__()` and no override, which is what the
     * site does today. Returning '' rather than guessing keeps that explicit.
     */
    private static function locale(): string
    {
        $locale = (string) app()->getLocale();

        return in_array($locale, self::LOCALES, true) ? $locale : '';
    }
}
