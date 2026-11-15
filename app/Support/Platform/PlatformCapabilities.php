<?php

namespace App\Support\Platform;

use App\Models\Plan;

/**
 * PHASE 03 M5 — WHAT THE OFFICIAL SITE IS ALLOWED TO CLAIM.
 * =================================================================================================
 *
 * The owner's instruction for M5 is blunt: "Do not invent supported features. Do not hardcode
 * fictional prices." A marketing page is the easiest place in a codebase to write something that
 * is not true, because nothing compiles it and no test naturally reads it. This class exists so
 * that the claims on the public site are not free text in a Blade file but DATA WITH AN ANCHOR,
 * and so a test can hold the anchors to account.
 *
 * TWO KINDS OF CLAIM, TWO KINDS OF ANCHOR.
 *
 *   1. A capability that a WorkDo module provides. Its anchor is `module`, and the capability is
 *      rendered only while `packages/workdo/<Module>` is on disk. Remove the directory and the
 *      claim disappears from the page -- which is exactly the falsification `PlatformCapabilities
 *      Test` performs, so the link between the page and the product is measured rather than
 *      asserted in a comment.
 *
 *   2. A capability that is first-party core (storefront themes, the theme editor, tenancy,
 *      bilingual UI). Its anchor is `null` for the module and the claim is grounded by a test that
 *      names the first-party artefact instead.
 *
 * WHAT "INSTALLED" HONESTLY MEANS, AND WHAT THE COPY THEREFORE SAYS.
 * A module being present on disk means it SHIPS WITH THE PLATFORM. It does NOT mean it is enabled
 * for a given merchant: `module_is_active()` resolves an `add_on_managers` row against a store and
 * a user, and the public site has neither. So every string this registry feeds is worded as what
 * the platform offers, never as what a particular store already has switched on. Saying otherwise
 * would be a claim the page cannot support.
 *
 * THE ENGLISH SENTENCE IS THE TRANSLATION KEY, which is the convention the rest of this codebase
 * uses and is chosen here for a specific reason: a key that is missing from `ar.json` degrades to
 * READABLE ENGLISH rather than to a dotted identifier printed on a public page. Every string added
 * here is declared in BOTH `resources/lang/en.json` and `resources/lang/ar.json`, because
 * `TranslationFileIntegrityTest` ratchets that `ar.json` declares no key `en.json` lacks.
 */
final class PlatformCapabilities
{
    /**
     * WHY THIS IS A CONSTANT AND NOT A DIRECTORY SCAN.
     *
     * Scanning `packages/workdo/` would put all 70 vendored module names on the public site,
     * including ones that are integrations with third parties, sign-in providers and internal
     * tooling. The site should describe the product, so the selection is editorial. What is NOT
     * editorial is whether a selected module exists: that is checked against the filesystem on
     * every render.
     *
     * @var array<string, list<array{title: string, body: string, module: ?string}>>
     */
    private const GROUPS = [

        /* What a merchant's public shop can do. */
        'storefront' => [
            ['title' => 'Themes you can choose between',
             'body'  => 'Every store picks a storefront theme. Themes ship with the platform and a plan decides which of them an account may use.',
             'module' => null],
            ['title' => 'A visual theme editor',
             'body'  => 'Change a theme\'s sections, text, images and colours from the dashboard. Work is saved as a draft, previewed privately, then published — and an earlier version can be restored.',
             'module' => null],
            ['title' => 'Light and dark',
             'body'  => 'The storefront renders in a light and a dark presentation, and both are checked for readable contrast before a theme ships.',
             'module' => null],
            ['title' => 'Arabic and English, right to left',
             'body'  => 'The shop lays itself out right to left in Arabic and left to right in English, from the same installation.',
             'module' => null],
            ['title' => 'Customer reviews',
             'body'  => 'Shoppers can rate and review what they bought, and you decide what appears on the product page.',
             'module' => 'ProductReview'],
            ['title' => 'Product comparison',
             'body'  => 'Shoppers can put products side by side before they choose.',
             'module' => 'ProductCompare'],
            ['title' => 'Quick view',
             'body'  => 'A product opens in place from a listing, without losing the page the shopper was on.',
             'module' => 'ProductQuickView'],
            ['title' => 'Store locator',
             'body'  => 'Publish the physical places a customer can visit or collect from.',
             'module' => 'StoreLocator'],
        ],

        /* Selling: catalogue, checkout, fulfilment. */
        'commerce' => [
            ['title' => 'Products, variants and stock',
             'body'  => 'Build a catalogue with categories, variants, images and stock levels, and keep prices and availability in one place.',
             'module' => null],
            ['title' => 'Orders from placed to delivered',
             'body'  => 'Follow an order through its statuses, record payment and shipping, and keep the history that goes with it.',
             'module' => null],
            ['title' => 'Digital products',
             'body'  => 'Sell files and downloads alongside physical goods.',
             'module' => 'DigitalProduct'],
            ['title' => 'Bundles',
             'body'  => 'Sell several products together as one purchasable item.',
             'module' => 'BundleProduct'],
            ['title' => 'Wholesale pricing',
             'body'  => 'Offer different prices to buyers who order in quantity.',
             'module' => 'WholesaleProduct'],
            ['title' => 'Pre-orders',
             'body'  => 'Take orders for something that has not arrived yet.',
             'module' => 'PreOrder'],
            ['title' => 'Partial payments',
             'body'  => 'Let a customer pay part of an order now and the rest later.',
             'module' => 'PartialPayments'],
            ['title' => 'Faster checkout',
             'body'  => 'Shorten the path between the cart and a completed order.',
             'module' => 'QuickCheckout'],
            ['title' => 'Import and update by file',
             'body'  => 'Bring a catalogue in from a spreadsheet instead of typing it, and update it the same way.',
             'module' => 'ProductCSV'],
            ['title' => 'Barcodes',
             'body'  => 'Generate and print barcodes for the products you stock.',
             'module' => 'ProductBarCode'],
        ],

        /* Reaching customers and bringing them back. */
        'growth' => [
            ['title' => 'Campaigns',
             'body'  => 'Plan and send a campaign to the customers you choose.',
             'module' => 'Campaigns'],
            ['title' => 'Marketing tools',
             'body'  => 'Reach customers through the channels the platform supports.',
             'module' => 'Marketing'],
            ['title' => 'Coupons and discounts',
             'body'  => 'Issue codes, set what they apply to and when they stop working.',
             'module' => 'CouponList'],
            ['title' => 'Loyalty points',
             'body'  => 'Reward repeat customers with points they can spend.',
             'module' => 'RewardClubPoint'],
            ['title' => 'Affiliates',
             'body'  => 'Let others refer customers to your shop and track what they bring.',
             'module' => 'ProductAffiliate'],
            ['title' => 'Countdown offers',
             'body'  => 'Show how long an offer has left to run.',
             'module' => 'CountDownTimer'],
            ['title' => 'Newsletter sign-up',
             'body'  => 'Collect the addresses of shoppers who want to hear from you.',
             'module' => 'Subscribe'],
            ['title' => 'Customer support desk',
             'body'  => 'Take questions and complaints as tickets instead of losing them in a chat.',
             'module' => 'Helpdesk'],
        ],

        /* Running the business day to day. */
        'operations' => [
            ['title' => 'More than one store',
             'body'  => 'Open several stores under one account, each with its own catalogue, orders, customers and theme. How many is set by the plan.',
             'module' => null],
            ['title' => 'Staff roles and permissions',
             'body'  => 'Give colleagues their own sign-in, and decide for each role what it may see and change.',
             'module' => null],
            ['title' => 'An admin app for the phone',
             'body'  => 'The Store Syria admin app reads the same account, so orders and products can be handled away from a computer.',
             'module' => null],
            ['title' => 'An API for the app and your own tools',
             'body'  => 'The platform exposes the API its own admin app uses.',
             'module' => 'Api'],
            ['title' => 'Import and export',
             'body'  => 'Move data in and out of the platform in bulk.',
             'module' => 'ImportExport'],
            ['title' => 'Backups',
             'body'  => 'Take a copy of the installation and restore from it.',
             'module' => 'BackupRestore'],
            ['title' => 'Two-factor sign-in',
             'body'  => 'Protect an account with a second step at sign-in.',
             'module' => 'Duo2FA'],
            ['title' => 'SMS notifications',
             'body'  => 'Send a message to a customer or to yourself when something happens.',
             'module' => 'SMS'],
        ],
    ];

    /** The group keys, in the order the site presents them. */
    public static function groupNames(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * The claims in a group that the product can currently support.
     *
     * An unknown group name returns an empty list rather than throwing: a view asking for a group
     * that no longer exists should render nothing, not 500 a public page.
     *
     * @return list<array{title: string, body: string, module: ?string}>
     */
    public static function available(string $group): array
    {
        $entries = self::GROUPS[$group] ?? [];

        return array_values(array_filter(
            $entries,
            static fn (array $e) => $e['module'] === null || self::moduleIsInstalled($e['module'])
        ));
    }

    /** Every claim in a group, whether or not its module ships. Used by the registry's own tests. */
    public static function declared(string $group): array
    {
        return self::GROUPS[$group] ?? [];
    }

    /**
     * Does this module ship with the platform?
     *
     * Deliberately a filesystem question and not `module_is_active()`. `module_is_active()` needs a
     * store and a user to resolve an `add_on_managers` row; a visitor to the public site is neither,
     * and under `null_tenant => 'open'` an unresolved tenant would make the answer meaningless
     * rather than merely unknown.
     */
    public static function moduleIsInstalled(string $module): bool
    {
        /* No separators, so a crafted name can never walk out of the packages directory. The
           registry is a constant, so this cannot be reached with caller input today; it is here so
           that it stays true if a later caller is less careful. */
        if ($module === '' || preg_match('/[^A-Za-z0-9]/', $module) === 1) {
            return false;
        }

        return is_dir(base_path('packages/workdo/' . $module));
    }

    /**
     * The storefront themes that actually exist.
     *
     * Read from `themes/` rather than listed in code, so the site cannot advertise a theme that was
     * removed, nor miss one that was added. Sorted for a stable render.
     *
     * @return list<string>
     */
    public static function themes(): array
    {
        $dirs = glob(base_path('themes') . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }

        $names = array_map('basename', $dirs);
        sort($names);

        return array_values($names);
    }

    /**
     * The capability facts of one plan, read from the `plans` row.
     *
     * EVERY VALUE HERE COMES FROM THE ROW THE SUBSCRIPTION FLOW CHARGES AGAINST. Nothing is
     * defaulted into existence: a limit that is absent is reported as absent and the view says so,
     * because "unlimited" and "not configured" are different promises and only one of them is safe
     * to print next to a price.
     *
     * `-1` IS THE SENTINEL FOR UNLIMITED, NOT `0`. MEASURED, because the first draft of this class
     * had it the other way round and would have printed "Unlimited" beside a plan that permits
     * nothing. `User.php:339` reads `$plan->max_stores == -1` as no cap; `DashboardController.php
     * :2966` reads `$total_products < $plan->max_products || $plan->max_products == -1`; and
     * `Utility.php:868` reads `$plan->storage_limit != -1` the same way. Under those checks a `0`
     * is a real limit of ZERO -- `0 < 0` is false and `0 == -1` is false -- so a plan whose column
     * is still the shipped `0` default sells no products at all. The site reports that as the
     * number it is, and `null` or an empty string as unspecified, because "unlimited", "zero" and
     * "not configured" are three different promises and only one of them is safe beside a price.
     *
     * `storage_limit` is in MEGABYTES (`DashboardController.php:7511` renders it as `. ' MB'`), so
     * its label carries the unit rather than the value being printed bare.
     *
     * @return list<array{label: string, value: string, state: 'yes'|'no'|'value'|'unknown'}>
     */
    public static function planHighlights(Plan $plan): array
    {
        return [
            self::limit('Stores included', $plan->max_stores),
            self::limit('Products', $plan->max_products),
            self::limit('Staff accounts', $plan->max_users),
            self::limit('Storage (MB)', $plan->storage_limit),
            self::flag('Custom domain', $plan->enable_domain),
            self::flag('Subdomain', $plan->enable_subdomain),
            self::flag('Shipping methods', $plan->shipping_method),
            self::flag('Tax rules', $plan->enable_tax),
            self::flag('Installable store app (PWA)', $plan->pwa_store),
        ];
    }

    /** @return array{label: string, value: string, state: 'value'|'unknown'} */
    private static function limit(string $label, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['label' => $label, 'value' => '', 'state' => 'unknown'];
        }

        $n = (int) $raw;

        return [
            'label' => $label,
            'value' => $n === -1 ? __('Unlimited') : number_format($n),
            'state' => 'value',
        ];
    }

    /** @return array{label: string, value: string, state: 'yes'|'no'|'unknown'} */
    private static function flag(string $label, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['label' => $label, 'value' => '', 'state' => 'unknown'];
        }

        return [
            'label' => $label,
            'value' => '',
            'state' => strtolower(trim((string) $raw)) === 'on' ? 'yes' : 'no',
        ];
    }
}
