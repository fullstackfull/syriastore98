{{--
    GAP-095 / P03-A-04 — THE STOREFRONT'S BYPASS BLOCK (WCAG 2.4.1, level A).

    Included as the FIRST element inside <body> by all four copies of
    `front_end/layouts/app.blade.php`, so it is the first thing Tab reaches. Every storefront page
    puts the whole header ahead of the content — logo, search, currency, language, appearance,
    account, cart and the full category menu — and without this a keyboard user tabbed through all
    of it on every page before reaching the first product.

    SHARED, NOT PER-THEME. It lives in `resources/views` and none of the three themes ships a
    `front_end/partials/` directory, so the same file resolves from every theme's prepended view
    root. Four layouts, one control: the alternative would be four copies to keep in step, which
    is the failure mode `CLAUDE.md` records for layout-level storefront changes.

    THE LABEL IS ALREADY TRANSLATED. "Skip to main content" is declared in
    `resources/lang/en.json:3397` and `resources/lang/ar.json:2682`
    ("الانتقال إلى المحتوى الرئيسي"), so this needed no language-file change and reads correctly in
    both directions. It is NOT `aria-label`-only: a visible-on-focus label is what a sighted
    keyboard user reads, and an invisible one would leave them pressing a key with no idea what it
    does.

    Styling is `public/css/storefront-keyboard.css`, loaded after each theme's `main-style.css`
    because the themes' own `outline: none` rules and Tailwind's runtime-injected `outline-none`
    both win on source order.
--}}
<a class="ss-skip-link" href="#ss-main">{{ __('Skip to main content') }}</a>
