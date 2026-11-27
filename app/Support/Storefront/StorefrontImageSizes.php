<?php

namespace App\Support\Storefront;

/**
 * THE `sizes` ATTRIBUTE OF EACH STOREFRONT IMAGE, AND THE MEASUREMENT IT COMES FROM.  GAP-274.
 * =================================================================================================
 *
 * `srcset` tells a browser what widths EXIST; `sizes` tells it how wide the box WILL BE, which it
 * has to know before layout in order to start the fetch from the preload scanner. Get `sizes`
 * wrong and the whole mechanism misfires in one of two ways: under-declare and the browser picks a
 * candidate narrower than the box and the picture is soft; over-declare and it picks a wider one
 * and the page is heavier than it was before any of this existed.
 *
 * So these are MEASURED, not estimated. Captured through the real storefront routes -- and for the
 * product list through the real `{slug}/product-filter` JSON that the page's own jQuery puts into
 * `.product_filter`, which is how the cards reach the DOM in production -- then read in Chromium
 * with the theme's runtime Tailwind JIT loaded, because CLAUDE.md §6 records that 270-294 rules are
 * injected at runtime and a box measured without them is a box no shopper sees. All three themes,
 * both directions; the figures below are identical in `ar` and `en`, which is itself worth knowing:
 * the storefront's RTL layout is a mirror, not a different grid.
 *
 *   element                         360   414   768  1024  1280  1536  1920   viewport width (CSS px)
 *   ------------------------------------------------------------------------
 *   product detail, main image      294   348   670   446   574   702   702
 *   product detail, thumbnail        87   105   155    99   131   163   163
 *   product card cover              328   382   358   355   288   352   352
 *
 * The non-monotonic columns are real and are the reason a single `vw` figure would be wrong: at
 * 1024 the product page becomes two columns (`lg:grid-cols-2`), so the image gets NARROWER than it
 * was at 768, and the card grid goes to three columns at `xl` (1280), so each card narrows there
 * too. The breakpoints below are Tailwind's own (`md` 768, `lg` 1024, `xl` 1280, `2xl` 1536), which
 * is what the measurement shows the layout changing at.
 *
 * Under the smallest breakpoint a `vw` figure is used rather than a pixel one, because the box is a
 * fraction of a viewport whose width is not known in advance. Each is rounded UP from the measured
 * fraction (main image 294/360 = 81.7% and 348/414 = 84.1% -> 85vw; card 328/360 = 91.1% and
 * 382/414 = 92.3% -> 92vw; thumbnail 87/360 = 24.2% and 105/414 = 25.4% -> 26vw), so the browser
 * is never told the box is smaller than it is.
 *
 * WHY A CONSTANT AND NOT A STRING IN EACH VIEW. The product card exists in FOUR copies -- the
 * `resources/views/components` fallback and one per theme -- and CLAUDE.md records that
 * `FileViewFinder::find()` memoises by view name, so under php-fpm whichever store a worker serves
 * FIRST fixes which copy every later store in that process renders. Four hand-written `sizes`
 * strings would drift, and the drift would only show on whichever copy happened to win.
 */
final class StorefrontImageSizes
{
    /** The product detail page's main gallery image. Its own LCP element. */
    public const PRODUCT_MAIN = '(min-width: 1536px) 702px, (min-width: 1280px) 574px, (min-width: 1024px) 446px, (min-width: 768px) 670px, 85vw';

    /** The product detail page's gallery thumbnails. */
    public const PRODUCT_THUMBNAIL = '(min-width: 1536px) 163px, (min-width: 1280px) 131px, (min-width: 1024px) 99px, (min-width: 768px) 155px, 26vw';

    /** The product card's cover image, on every listing and slider that renders `<x-product-card>`. */
    public const PRODUCT_CARD = '(min-width: 1536px) 352px, (min-width: 1280px) 288px, (min-width: 1024px) 355px, (min-width: 768px) 358px, 92vw';
}
