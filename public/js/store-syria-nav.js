/* ==========================================================================
   Store Syria — sidebar navigation behaviour (P2-01-11)
   ==========================================================================

   Loaded by layouts/app.blade.php on EVERY page, because the sidebar is on
   every page. It is deliberately NOT part of public/js/store-syria-ds.js:
   that bundle ships through layouts/includes/store-syria-ds.blade.php, which
   is included per-screen (dashboard, attribute_option, the previews). A
   sidebar behaviour that only worked on two screens would be worse than none.

   Two things live here:

     1. the workspace chip's store switcher — a listbox, keyboard-operable;
     2. the collapsed rail's persistence and its aria-pressed state.

   WHAT IT DOES NOT DO: decide anything about access. The switcher's options
   are rendered server-side by partision/workspace-chip.blade.php from
   User::switchableStores(), and following one is an ordinary navigation to
   StoreController::changeStore(), which does its own ownership check. Nothing
   here is a gate, and no check here may ever be relied on as one.
   ========================================================================== */
(function () {
    'use strict';

    /* ---------------------------------------------------------------- rail */

    var RAIL_KEY = 'ss-nav-rail';

    /* Reading localStorage can THROW (not return null) in a private window
       with site data blocked, so every access is guarded. A failure means the
       rail simply does not persist, which is a degraded preference and not a
       broken page. */
    function readRail() {
        try { return localStorage.getItem(RAIL_KEY); } catch (e) { return null; }
    }
    function writeRail(v) {
        try { localStorage.setItem(RAIL_KEY, v); } catch (e) { /* ignore */ }
    }

    function syncRailButton(btn) {
        var collapsed = document.body.classList.contains('minimenu');
        btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        /* The accessible name has to describe what the button will DO next,
           not what it did. A toggle whose name stays "Collapse menu" while it
           is already collapsed tells a screen-reader user the opposite of the
           truth. */
        var label = collapsed ? btn.dataset.ssExpand : btn.dataset.ssCollapse;
        if (label) {
            btn.setAttribute('title', label);
            var sr = btn.querySelector('.ss-visually-hidden');
            if (sr) sr.textContent = label;
        }
    }

    function initRail() {
        var btn = document.getElementById('vertical-nav-toggle');
        if (!btn) return;

        /* The CLASS TOGGLE ITSELF IS NOT OURS. public/assets/js/dash.js:71
           already listens on this id and does the real work, including closing
           open submenus and calling collapseedge(). Duplicating that here would
           give two handlers fighting over one class. This listener only
           persists the result and keeps the button's state honest — and it runs
           after dash.js's because dash.js is loaded earlier in the page. */
        btn.addEventListener('click', function () {
            // Let dash.js finish its own handler before reading the class.
            window.setTimeout(function () {
                writeRail(document.body.classList.contains('minimenu') ? 'collapsed' : 'expanded');
                syncRailButton(btn);
            }, 0);
        });

        syncRailButton(btn);
    }

    /* ----------------------------------------------------------- workspace */

    function initWorkspace() {
        var root = document.querySelector('[data-ss-workspace]');
        if (!root) return;

        var trigger = root.querySelector('#ss-workspace-trigger');
        var list = root.querySelector('#ss-workspace-list');
        if (!trigger || !list) return;   // the single-store chip is static

        /* `.ss-menu__item`, not `.ss-workspace__option`.
         *
         * The first version queried `.ss-workspace__option` and
         * `.ss-workspace__option.is-current` — classes that exist NOWHERE in
         * the repository. They were left behind when the chip was refactored
         * to use the design system's own `.ss-menu` / `.ss-menu__item`. The
         * result was not a cosmetic miss: `options()` returned an empty array,
         * so ArrowDown on the trigger opened the list and focused nothing, and
         * then ArrowDown INSIDE the list evaluated `opts[(i + 1) % 0]` —
         * `opts[NaN]` — and threw "Cannot read properties of undefined".
         * Mouse users saw a working menu; keyboard users got a dead control
         * and a console error. */
        function options() {
            return Array.prototype.slice.call(list.querySelectorAll('.ss-menu__item'));
        }

        function open(focusWhich) {
            list.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            var opts = options();
            if (!opts.length) return;
            /* aria-selected is the current store, and it is the SAME fact the
               screen reader is given — not a parallel `is-current` class that
               could drift away from it. */
            var target = focusWhich === 'last' ? opts[opts.length - 1]
                : (list.querySelector('[aria-selected="true"]') || opts[0]);
            target.focus();
        }

        function close(returnFocus) {
            if (list.hidden) return;
            list.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            /* Focus must come BACK to the trigger, or a keyboard user is
               dropped at the top of the document and has to tab in again. */
            if (returnFocus) trigger.focus();
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            list.hidden ? open() : close(true);
        });

        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Down') { e.preventDefault(); open('first'); }
            else if (e.key === 'ArrowUp' || e.key === 'Up') { e.preventDefault(); open('last'); }
        });

        list.addEventListener('keydown', function (e) {
            var opts = options();
            /* Guard before any modulo. Even with the selector corrected, an
               empty list must not turn a key press into a TypeError — a
               rendering change upstream should degrade to "nothing happens",
               not to a broken page. */
            if (!opts.length) return;
            var i = opts.indexOf(document.activeElement);
            if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); close(true); }
            else if (e.key === 'ArrowDown' || e.key === 'Down') {
                e.preventDefault(); opts[(i + 1) % opts.length].focus();
            } else if (e.key === 'ArrowUp' || e.key === 'Up') {
                e.preventDefault(); opts[(i - 1 + opts.length) % opts.length].focus();
            } else if (e.key === 'Home') { e.preventDefault(); opts[0].focus(); }
            else if (e.key === 'End') { e.preventDefault(); opts[opts.length - 1].focus(); }
            else if (e.key === 'Tab') {
                /* Tabbing out is a dismissal, but the focus must NOT be pulled
                   back to the trigger or Tab could never leave the control. */
                close(false);
            }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) close(false);
        });
    }

    function init() {
        initRail();
        initWorkspace();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
