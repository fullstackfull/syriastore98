{{--
    M2-A — THE STOREFRONT APPEARANCE FOUNDATION.

    Included from each theme's own `front_end/layouts/app.blade.php`, inside <head>, BEFORE the
    theme stylesheet. Three jobs, in this order, and the order is the point.

    1. LOAD THE DESIGN SYSTEM. M1 measured that the storefront loads `store-syria-ds.css` NOWHERE,
       so its ~146 dark-mode rules were unavailable to it. It is loaded before the theme sheet so a
       theme can override a token; it defines `--ss-*` on `:root` and re-declares them under
       `[data-ss-theme="dark"]`.

       SAFE TO LOAD, MEASURED RATHER THAN ASSUMED: of 708 selectors, 705 are scoped by `.ss-` or
       `data-ss-`. The three that are not are `.dataTables_processing` and `.invalid-tooltip`
       (zero storefront views) and `.invalid-feedback` (three views, all in the authenticated
       customer area), and the only rule reaching them is `color: var(--ss-danger)`. The sheet's own
       note explains why it must not touch their `display`: doing so "would show every message on
       every form at all times."

    2. SET THE APPEARANCE BEFORE FIRST PAINT. The script is inline, synchronous, and ahead of the
       stylesheet on purpose. A deferred or external script would let the light paint land first and
       then flip -- the visible flash the directive forbids.

    3. SCOPE THE PREFERENCE TO THIS STORE. All stores share one origin, so one unscoped key would
       let a shopper's choice on store A silently re-theme store B. The key carries the store's
       NUMERIC id rather than its slug: the id cannot contain a character needing encoding, cannot
       collide, and -- unlike the slug, which `updateStoreOwnerInfo` lets a merchant change -- does
       not move, so a slug change does not silently discard a shopper's choice.

    This is localStorage ONLY. The merchant admin's dark mode is a server-side `cust_darklayout`
    store setting; nothing here reads or writes it, so a shopper cannot affect how a merchant's
    admin looks, and a merchant's admin choice does not follow shoppers onto the storefront.
--}}

<link rel="stylesheet" href="{{ asset('css/store-syria-ds.css') }}">

@php
    // The resolved store, from the view composer. A storefront page without one cannot render at
    // all (ViewServiceProvider aborts 404), so the `?? 0` is belt-and-braces for a preview route
    // rather than a real branch.
    $ssAppearanceKey = 'store-syria:storefront:appearance:s' . (int) ($store->id ?? 0);
@endphp

<script>
    (function () {
        var KEY = @json($ssAppearanceKey);
        var root = document.documentElement;
        var stored = null;

        // localStorage THROWS, not returns null, in a private window or with site data blocked --
        // and an exception here would abort the script and leave the page with no appearance
        // attribute at all. Every access is guarded for that reason.
        try {
            stored = window.localStorage.getItem(KEY);
        } catch (e) {
            stored = null;
        }

        // `auto` is the design system's own name for "follow prefers-color-scheme", and it applies
        // only where a page asks for it -- which is why the default is written explicitly instead
        // of being left absent.
        root.setAttribute('data-ss-theme', stored === 'dark' || stored === 'light' ? stored : 'auto');
        root.setAttribute('data-ss-theme-source', stored ? 'shopper' : 'system');
        root.setAttribute('data-ss-appearance-key', KEY);
    })();
</script>

{{-- The control's own styling. Confined to `.ss-appearance-toggle`, so it cannot reach theme
     markup, and built from `--ss-*` tokens so it re-themes with everything else. --}}
<style>
    .ss-appearance-toggle {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        inline-size: 2.25rem;
        block-size: 2.25rem;
        padding: 0;
        border: 1px solid var(--ss-border, #d8dce2);
        border-radius: 999px;
        background: var(--ss-surface, #fff);
        color: var(--ss-fg, #1a1d23);
        cursor: pointer;
        /* Logical properties only: this control sits in a header that mirrors in RTL. */
        margin-inline-start: 0.25rem;
        transition: background-color 140ms ease, border-color 140ms ease, color 140ms ease;
    }
    .ss-appearance-toggle:hover { background: var(--ss-surface-hover, #f2f4f7); }
    /* A VISIBLE focus ring, not the removed default. `:focus-visible` keeps it off mouse clicks. */
    .ss-appearance-toggle:focus-visible {
        outline: 2px solid var(--ss-focus, #2f6feb);
        outline-offset: 2px;
    }
    .ss-appearance-toggle__icon { inline-size: 1.15rem; block-size: 1.15rem; display: none; }
    [data-ss-theme] .ss-appearance-toggle[data-state="auto"]  .ss-appearance-toggle__icon--auto,
    [data-ss-theme] .ss-appearance-toggle[data-state="light"] .ss-appearance-toggle__icon--light,
    [data-ss-theme] .ss-appearance-toggle[data-state="dark"]  .ss-appearance-toggle__icon--dark { display: block; }
    /* Before the script runs, show the auto glyph rather than an empty circle. */
    .ss-appearance-toggle:not([data-state]) .ss-appearance-toggle__icon--auto { display: block; }
    /* The live-region text is for assistive technology only; it must not occupy layout. */
    .ss-appearance-toggle__state {
        position: absolute;
        inline-size: 1px; block-size: 1px;
        margin: -1px; padding: 0; border: 0;
        overflow: hidden; white-space: nowrap;
        clip-path: inset(50%);
    }
    /* The directive requires reduced motion to be respected. */
    @media (prefers-reduced-motion: reduce) {
        .ss-appearance-toggle { transition: none; }
    }
</style>

<script>
    (function () {
        var ORDER = ['auto', 'light', 'dark'];

        function apply(state, source) {
            var root = document.documentElement;
            root.setAttribute('data-ss-theme', state);
            root.setAttribute('data-ss-theme-source', source);
        }

        function wire(btn) {
            var KEY = document.documentElement.getAttribute('data-ss-appearance-key');
            var labels = {
                auto: btn.getAttribute('data-label-auto') || 'Follow device',
                light: btn.getAttribute('data-label-light') || 'Light',
                dark: btn.getAttribute('data-label-dark') || 'Dark'
            };
            var prefix = btn.getAttribute('data-label-appearance') || 'Appearance';
            var activate = btn.getAttribute('data-label-activate') || 'Activate to switch to :mode';
            var live = btn.querySelector('.ss-appearance-toggle__state');

            function read() {
                try {
                    var v = window.localStorage.getItem(KEY);
                    return v === 'light' || v === 'dark' ? v : 'auto';
                } catch (e) {
                    // Blocked or unavailable storage is not an error state for the shopper: the
                    // control still works for this page view, it just cannot remember.
                    return 'auto';
                }
            }

            function write(state) {
                try {
                    if (state === 'auto') { window.localStorage.removeItem(KEY); }
                    else { window.localStorage.setItem(KEY, state); }
                    return true;
                } catch (e) {
                    return false;
                }
            }

            function render(state) {
                btn.setAttribute('data-state', state);
                var next = ORDER[(ORDER.indexOf(state) + 1) % ORDER.length];
                // The accessible name carries the CURRENT state and what activating will do.
                // `aria-pressed` is deliberately absent: it is binary and this control is ternary.
                btn.setAttribute('aria-label',
                    prefix + ': ' + labels[state] + '. ' + activate.replace(':mode', labels[next]));
                if (live) { live.textContent = prefix + ': ' + labels[state]; }
            }

            var state = read();
            apply(state, state === 'auto' ? 'system' : 'shopper');
            render(state);

            btn.addEventListener('click', function () {
                state = ORDER[(ORDER.indexOf(state) + 1) % ORDER.length];
                var persisted = write(state);
                apply(state, state === 'auto' ? 'system' : 'shopper');
                render(state);
                // Say so when the choice could not be remembered, rather than pretending.
                btn.setAttribute('data-ss-persisted', persisted ? 'yes' : 'no');
            });

            // `auto` MUST FOLLOW THE DEVICE, or it is the decorative control the directive forbids.
            // The design system already re-themes on `prefers-color-scheme` for `data-ss-theme="auto"`,
            // so nothing has to be recomputed here -- but the announced label does.
            if (window.matchMedia) {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                var onChange = function () { if (state === 'auto') { render('auto'); } };
                if (mq.addEventListener) { mq.addEventListener('change', onChange); }
                else if (mq.addListener) { mq.addListener(onChange); }
            }
        }

        function init() {
            var nodes = document.querySelectorAll('[data-ss-appearance-toggle]');
            for (var i = 0; i < nodes.length; i++) { wire(nodes[i]); }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>
