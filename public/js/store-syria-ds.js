/* ==========================================================================
   Store Syria design system — behaviour (Phase 02)
   ==========================================================================

   Loaded per page, beside the stylesheet, by
   resources/views/layouts/includes/store-syria-ds.blade.php. Like the
   stylesheet it is additive and opt-in: a page that does not include the
   partial is byte-for-byte unaffected.

   WHAT IT DOES NOT DO
   It does not touch jQuery, Bootstrap, the host's `data-ajax-popup` modal
   contract, `show_toastr`, or the inline sidebar search. Those all keep
   working exactly as they do today. Four independent behaviours:

     1. side panel        focus, Tab containment, Esc, scroll lock
     2. dirty state       a beforeunload guard that does not cry wolf
     3. error summary     move focus to it after a failed submit
     4. disclosure        the chart's "show as table" fallback

   NO DEPENDENCIES. Plain DOM, so it cannot be broken by a jQuery upgrade and
   it runs before jQuery has loaded.
   ========================================================================== */

(function () {
    'use strict';

    /* ---------------------------------------------------------------- utils */

    var FOCUSABLE = [
        'a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])'
    ].join(',');

    function focusablesIn(root) {
        return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), function (el) {
            // offsetParent is null for display:none; a panel is visible by the
            // time this runs, so this only filters genuinely hidden children.
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    /* ----------------------------------------------------------- side panel */

    var panelState = {}; // id -> { trigger: Element }

    function panelEls(id) {
        return {
            panel: document.getElementById(id),
            backdrop: document.querySelector('[data-ss-panel-backdrop="' + id + '"]')
        };
    }

    function openPanel(id, trigger) {
        var els = panelEls(id);
        if (!els.panel) { return; }

        panelState[id] = { trigger: trigger || document.activeElement };

        if (els.backdrop) { els.backdrop.hidden = false; }
        els.panel.hidden = false;

        // Two frames: one for `hidden` to clear, one for the transform to be
        // the starting point of a transition rather than the end of one.
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                els.panel.setAttribute('data-ss-state', 'open');
            });
        });

        // The list behind must not scroll while the panel is over it.
        document.body.style.overflow = 'hidden';

        var heading = els.panel.querySelector('.ss-panel__title');
        if (heading) {
            heading.focus();
        } else {
            var first = focusablesIn(els.panel)[0];
            if (first) { first.focus(); }
        }
    }

    function closePanel(id) {
        var els = panelEls(id);
        if (!els.panel) { return; }

        els.panel.setAttribute('data-ss-state', 'closed');
        document.body.style.overflow = '';

        var done = function () {
            els.panel.hidden = true;
            if (els.backdrop) { els.backdrop.hidden = true; }
            els.panel.removeEventListener('transitionend', done);
        };
        els.panel.addEventListener('transitionend', done);
        // prefers-reduced-motion drops the transition, so transitionend never
        // fires and the panel would stay in the tree. Belt and braces.
        setTimeout(done, 300);

        // Focus returns to whatever opened it, which is the whole point of
        // tracking the trigger.
        var st = panelState[id];
        if (st && st.trigger && document.contains(st.trigger)) {
            st.trigger.focus();
        }
        delete panelState[id];
    }

    function openPanelIds() { return Object.keys(panelState); }

    /* P2-04-5. REMOTE PANEL CONTENT.

       `data-ss-panel-url` was documented on the panel component from the
       start and was never implemented here, so a trigger carrying it opened
       an empty panel. It is implemented now because the order quick preview
       is the first screen that needs it.

       THE PANEL OPENS FIRST, then fills. Opening after the fetch would mean a
       click with no feedback for as long as the request takes, and would move
       focus long after the user's action. So the panel opens with a loading
       state, which is also what keeps the focus move tied to the click.

       A FAILED FETCH SAYS SO. The server answers 403 or 404 for an order the
       caller may not see -- that is the guard and the handler doing their job
       -- and the panel must report it rather than sit on a spinner. The
       message is the server's own when it sends one, because the handler
       distinguishes "not found" from "not permitted" deliberately. */

    function panelBody(id) {
        var els = panelEls(id);
        return els.panel ? els.panel.querySelector('[data-ss-panel-body]') : null;
    }

    function loadPanel(id, url) {
        var body = panelBody(id);
        if (!body) { return; }

        body.setAttribute('aria-busy', 'true');
        body.innerHTML = '<p class="ss-panel__loading" role="status">'
            + (body.getAttribute('data-ss-loading-text') || 'Loading\u2026')
            + '</p>';

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.text().then(function (text) {
                return { ok: response.ok, status: response.status, text: text };
            });
        }).then(function (result) {
            body.removeAttribute('aria-busy');

            if (result.ok) {
                body.innerHTML = result.text;
                return;
            }

            var message = '';
            try {
                message = (JSON.parse(result.text) || {}).message || '';
            } catch (err) {
                message = '';
            }

            /* `role="alert"` rather than `status`: this is an error, and the
               panel already has focus inside it, so it must be announced. */
            body.innerHTML = '<p class="ss-panel__error" role="alert"></p>';
            body.firstChild.textContent = message
                || (body.getAttribute('data-ss-error-text') || 'This could not be loaded.');
        }).catch(function () {
            body.removeAttribute('aria-busy');
            body.innerHTML = '<p class="ss-panel__error" role="alert"></p>';
            body.firstChild.textContent =
                body.getAttribute('data-ss-error-text') || 'This could not be loaded.';
        });
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest && e.target.closest('[data-ss-panel-open]');
        if (opener) {
            e.preventDefault();
            var panelId = opener.getAttribute('data-ss-panel-open');
            openPanel(panelId, opener);

            var url = opener.getAttribute('data-ss-panel-url');
            if (url) { loadPanel(panelId, url); }
            return;
        }

        var closer = e.target.closest && e.target.closest('[data-ss-panel-close]');
        if (closer) {
            e.preventDefault();
            closePanel(closer.getAttribute('data-ss-panel-close'));
            return;
        }

        if (e.target.hasAttribute && e.target.hasAttribute('data-ss-panel-backdrop')) {
            closePanel(e.target.getAttribute('data-ss-panel-backdrop'));
        }
    });

    document.addEventListener('keydown', function (e) {
        var ids = openPanelIds();
        if (!ids.length) { return; }
        var id = ids[ids.length - 1];

        if (e.key === 'Escape') {
            e.preventDefault();
            closePanel(id);
            return;
        }

        if (e.key !== 'Tab') { return; }

        // Containment. Without this, Tab walks out of the panel into the list
        // behind it, which is still in the accessibility tree.
        var panel = document.getElementById(id);
        if (!panel) { return; }
        var items = focusablesIn(panel);
        if (!items.length) { return; }

        var first = items[0];
        var last = items[items.length - 1];

        if (e.shiftKey && (document.activeElement === first || !panel.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    /* ---------------------------------------------------------- dirty state */

    /* Armed on real input only -- not on focus, and not on a programmatic
       value change. A guard that fires when the merchant changed nothing is a
       guard they learn to click through, which is worse than no guard. */
    function wireDirtyTracking(form) {
        if (form.hasAttribute('data-ss-dirty-wired')) { return; }
        form.setAttribute('data-ss-dirty-wired', 'true');

        var markDirty = function () {
            if (form.getAttribute('data-ss-dirty') !== 'true') {
                form.setAttribute('data-ss-dirty', 'true');
            }
        };

        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);

        // A submit is not a loss of work.
        form.addEventListener('submit', function () {
            form.setAttribute('data-ss-dirty', 'false');
        });
    }

    function anyDirty() {
        return !!document.querySelector('form[data-ss-dirty="true"]');
    }

    window.addEventListener('beforeunload', function (e) {
        if (!anyDirty()) { return; }
        // Browsers show their own wording; returnValue is what arms it.
        e.preventDefault();
        e.returnValue = '';
        return '';
    });

    /* -------------------------------------------------------- error summary */

    /* Focus moves to the summary after a FAILED SUBMIT -- which, server-side,
       means the summary is present in the markup on page load. It is never
       moved on blur. */
    function focusErrorSummary() {
        var summary = document.querySelector('[data-ss-error-summary]');
        if (summary) { summary.focus(); }
    }

    /* ------------------------------------------------------------ disclosure */

    /* The chart's accessible fallback, and any other "show the data as a
       table" control. aria-expanded carries the state. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-ss-disclose]');
        if (!btn) { return; }
        e.preventDefault();

        var target = document.getElementById(btn.getAttribute('data-ss-disclose'));
        if (!target) { return; }

        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        target.hidden = open;
    });

    /* ------------------------------------------------------------------ init */

    function init() {
        Array.prototype.forEach.call(
            document.querySelectorAll('form[data-ss-dirty-track]'),
            wireDirtyTracking
        );
        focusErrorSummary();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* A tiny public surface, so a screen can drive a panel from its own
       script -- the orders list opens the preview panel after its fetch
       resolves, for instance. */
    window.StoreSyriaDS = {
        openPanel: openPanel,
        closePanel: closePanel,
        loadPanel: loadPanel,
        wireDirtyTracking: wireDirtyTracking
    };
})();
