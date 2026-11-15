<?php

namespace App\Support\Theme;

/**
 * PHASE 03 M4-5 — THE ONE SEAM A DRAFT PREVIEW CAN USE, and why the obvious ones do not work.
 * =================================================================================================
 *
 * The task is to render a storefront page from an UNPUBLISHED configuration, for the owning tenant
 * only, without writing anything and without a shopper ever seeing it. Three obvious approaches are
 * each closed by something measured on this codebase:
 *
 *   1. PASS THE DRAFT FROM A CONTROLLER. It is silently overwritten.
 *      `ViewServiceProvider:23` composes `front_end.layouts.app`, `main_file`, `front_end.pages.*`
 *      and `front_end.Auth.*`, and at `:65` it RECOMPUTES
 *      `$themeSettings = getThemeSetting($store->id, $store->theme_id)`. A view composer runs after
 *      the controller and its data is merged over the controller's, so the draft array disappears
 *      with no error. Verified at source 2026-11-15.
 *
 *   2. ADD A `{theme}` ROUTE PARAMETER. It switches on a latent branch with no validation and a
 *      store-1 fallback. `ViewServiceProvider:50-55` reads
 *      `else if (request()->route('theme')) { $store = Store::find(getCurrentStore()); $store->theme_id = request()->route('theme'); }`
 *      -- the unvalidated route string assigned straight to `theme_id`, and the store resolved by
 *      the very `getCurrentStore()` fallback M4-1 and M4-2 spent two slices removing. It is
 *      unreachable today only because the `{theme}` routes render `theme_customize.*`, an ADMIN
 *      view the composer does not bind. A preview route rendering a storefront view turns it on.
 *
 *   3. PUT THE THEME IN THE PATH, e.g. `/preview/themes/<name>/...`. `ActiveTheme:71` does
 *      `array_search('themes', $request->segments())` and takes the NEXT segment as the theme,
 *      whenever `$store && $store->theme_id` is falsy -- which includes a store whose `theme_id` is
 *      the empty string, i.e. exactly the pre-launch state a merchant previews from.
 *
 * SO THE SEAM IS HERE: a request-scoped value that `getThemeSetting()` itself consults, below the
 * composer, below the controller, and below anything that could overwrite it.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE PROPERTIES THIS CLASS MUST HAVE, each for a measured reason:
 *
 *   IT IS KEYED ON (store, theme), not global. `getThemeSetting()` is called for whatever store the
 *   request resolved; an override that answered for ANY arguments would leak a draft into a page
 *   rendered for a different store in the same request.
 *
 *   IT IS IDEMPOTENT. The composer fires TWICE on a storefront page, because `@extends` makes the
 *   layout its own View. A one-shot that popped its value would serve the draft to the layout and
 *   the published configuration to the page, or the reverse.
 *
 *   IT IS REQUEST-SCOPED, never static. Bound `scoped` in the container, so it cannot survive into
 *   another request in a worker that keeps the application between requests. A draft leaking into
 *   an anonymous shopper's request is the one failure this whole slice exists to prevent, and
 *   `static` state is how it would happen.
 */
final class ThemePreviewContext
{
    private ?int $storeId = null;

    private ?string $themeId = null;

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    private ?int $versionId = null;

    /**
     * Arm the preview for exactly one (store, theme) pair for the rest of this request.
     *
     * @param array<string,mixed> $settings key-complete, as `ThemePublishingService::preview()`
     *                                      returns it -- ~520 keys, because storefront views index
     *                                      them with no guard and an absent key is a 500.
     */
    public function arm(int $storeId, string $themeId, array $settings, ?int $versionId = null): void
    {
        $this->storeId   = $storeId;
        $this->themeId   = $themeId;
        $this->settings  = $settings;
        $this->versionId = $versionId;
    }

    /**
     * The draft settings for this exact pair, or null -- in which case the caller reads the live
     * projection as it always has. Returning null for a non-matching pair is the containment.
     *
     * @return array<string,mixed>|null
     */
    public function settingsFor(mixed $storeId, mixed $themeId): ?array
    {
        if ($this->settings === null) {
            return null;
        }

        if ((int) $storeId !== $this->storeId || (string) $themeId !== $this->themeId) {
            return null;
        }

        return $this->settings;
    }

    public function isArmed(): bool
    {
        return $this->settings !== null;
    }

    public function versionId(): ?int
    {
        return $this->versionId;
    }

    /** For tests and for an explicit teardown; a request boundary clears it anyway. */
    public function disarm(): void
    {
        $this->storeId = null;
        $this->themeId = null;
        $this->settings = null;
        $this->versionId = null;
    }
}
