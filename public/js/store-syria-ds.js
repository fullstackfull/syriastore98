/* ==========================================================================
   Store Syria design system — behaviour (Phase 02)
   ==========================================================================

   LOADED IN THE ADMIN LAYOUT, beside the stylesheet (layouts/app.blade.php),
   because P2-04-5 needs it on screens that never opted in -- the order list's
   quick-preview panel is driven from `order/index.blade.php`, which does not
   include the partial, so the panel opened with an empty body and the fetch
   never ran. MEASURED in the running application: eight screenshot
   combinations all reported `panel=unknown` with an empty body before this
   moved.

   STILL ADDITIVE. Everything below is inert without the markup it looks for,
   so a screen that has no panel, no dirty-tracked form and no error summary is
   byte-for-byte unaffected. The per-screen include at
   layouts/includes/store-syria-ds.blade.php remains for a screen outside this
   layout (guest, theme_layout) and is a duplicate on the two admin screens
   that still call it -- which is why the whole file is guarded against running
   twice: a duplicate <script src> is fetched once and EXECUTED TWICE, and a
   second execution would bind the panel's click handler again and open every
   panel twice.

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

    /* ONE INITIALISATION PER DOCUMENT. See the header: this file is in the admin
       layout AND in an opt-in partial two screens still include, and a repeated
       <script src> executes again rather than being skipped. */
    if (window.StoreSyriaDS) { return; }

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

    /* ------------------------------------------------------- command palette */

    /* P2-06-2. The merchant's global search.

       EVERY RESULT IS DECIDED SERVER-SIDE. This renders what the endpoint returns and filters
       nothing: a palette that filtered client-side would have to be given rows the caller may
       not see in order to hide them, which is the whole defect it would be pretending to avoid.

       TEXT IS SET WITH textContent, NEVER innerHTML. Product names, customer names and order
       references are merchant-entered, so building the row markup by concatenation would make
       this a stored-XSS sink in the one component every admin page loads.

       THE REQUEST IS DEBOUNCED AND SUPERSEDED. A keystroke cancels the previous fetch's
       RENDERING (AbortController cancels the request itself where supported), because
       out-of-order responses are how a palette ends up showing results for a term the user has
       already finished deleting. */

    var cmd = {
        root: null, input: null, list: null, status: null, backdrop: null,
        trigger: null, timer: null, controller: null, items: [], active: -1
    };

    function cmdEls() {
        if (cmd.root) { return cmd.root; }
        cmd.root = document.getElementById('ss-command');
        cmd.input = document.getElementById('ss-command-input');
        cmd.list = document.getElementById('ss-command-results');
        cmd.status = document.getElementById('ss-command-status');
        cmd.backdrop = document.querySelector('[data-ss-command-backdrop]');
        return cmd.root;
    }

    function cmdNote(text) {
        cmd.list.innerHTML = '';
        var li = document.createElement('li');
        li.className = 'ss-command__note';
        li.setAttribute('role', 'presentation');
        li.textContent = text;
        cmd.list.appendChild(li);
        cmd.items = [];
        cmd.active = -1;
        cmd.input.setAttribute('aria-expanded', 'false');
        cmd.input.removeAttribute('aria-activedescendant');
    }

    function cmdOpen(trigger) {
        if (!cmdEls()) { return; }
        cmd.trigger = trigger || document.activeElement;
        if (cmd.backdrop) { cmd.backdrop.hidden = false; }
        cmd.root.hidden = false;
        document.body.style.overflow = 'hidden';
        cmd.input.value = '';
        cmdNote(cmd.input.getAttribute('data-ss-hint-text') || '');
        cmd.input.focus();
    }

    function cmdClose() {
        if (!cmdEls() || cmd.root.hidden) { return; }
        cmd.root.hidden = true;
        if (cmd.backdrop) { cmd.backdrop.hidden = true; }
        document.body.style.overflow = '';
        if (cmd.controller) { try { cmd.controller.abort(); } catch (e) {} cmd.controller = null; }
        if (cmd.trigger && document.contains(cmd.trigger)) { cmd.trigger.focus(); }
        cmd.trigger = null;
    }

    function cmdRender(payload) {
        var sections = (payload && payload.sections) || [];
        cmd.list.innerHTML = '';
        cmd.items = [];
        cmd.active = -1;

        if (!sections.length) {
            cmdNote(cmd.input.getAttribute('data-ss-empty-text') || '');
            cmd.status.textContent = cmd.input.getAttribute('data-ss-empty-text') || '';
            return;
        }

        var index = 0;
        sections.forEach(function (section) {
            var head = document.createElement('li');
            head.className = 'ss-command__group';
            head.setAttribute('role', 'presentation');
            head.textContent = section.label;
            cmd.list.appendChild(head);

            (section.items || []).forEach(function (item) {
                var li = document.createElement('li');
                li.setAttribute('role', 'presentation');

                var a = document.createElement('a');
                a.className = 'ss-command__item';
                a.id = 'ss-command-item-' + (index++);
                a.setAttribute('role', 'option');
                a.setAttribute('aria-selected', 'false');
                a.href = item.href || '#';

                /* A row that opens a PANEL rather than navigating carries the panel id, so the
                   order preview reuses the surface the orders list already uses. */
                if (item.panel) {
                    a.setAttribute('data-ss-panel-open', item.panel);
                    a.setAttribute('data-ss-panel-url', item.href);
                }

                var title = document.createElement('span');
                title.className = 'ss-command__title';
                title.textContent = item.title == null ? '' : String(item.title);
                a.appendChild(title);

                if (item.meta) {
                    var meta = document.createElement('span');
                    meta.className = 'ss-command__meta';
                    meta.textContent = String(item.meta);
                    a.appendChild(meta);
                }

                li.appendChild(a);
                cmd.list.appendChild(li);
                cmd.items.push(a);
            });
        });

        cmd.input.setAttribute('aria-expanded', cmd.items.length ? 'true' : 'false');
        cmd.status.textContent = String(cmd.items.length);
        if (cmd.items.length) { cmdActivate(0); }
    }

    function cmdActivate(i) {
        if (!cmd.items.length) { return; }
        if (cmd.active >= 0 && cmd.items[cmd.active]) {
            cmd.items[cmd.active].setAttribute('aria-selected', 'false');
        }
        cmd.active = (i + cmd.items.length) % cmd.items.length;
        var el = cmd.items[cmd.active];
        el.setAttribute('aria-selected', 'true');
        /* aria-activedescendant, NOT focus(): the caret has to stay in the input so the user can
           keep typing while the selection moves. */
        cmd.input.setAttribute('aria-activedescendant', el.id);
        if (el.scrollIntoView) { el.scrollIntoView({ block: 'nearest' }); }
    }

    function cmdSearch() {
        var term = cmd.input.value.trim();
        var url = cmd.input.getAttribute('data-ss-search-url');

        if (term.length < 2) {
            cmdNote(cmd.input.getAttribute('data-ss-hint-text') || '');
            return;
        }

        if (cmd.controller) { try { cmd.controller.abort(); } catch (e) {} }
        cmd.controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        cmdNote(cmd.input.getAttribute('data-ss-searching-text') || '');

        fetch(url + '?q=' + encodeURIComponent(term), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: cmd.controller ? cmd.controller.signal : undefined
        }).then(function (r) {
            if (!r.ok) { throw new Error(String(r.status)); }
            return r.json();
        }).then(function (payload) {
            // Superseded while in flight: the box has moved on, so this answer is stale.
            if (cmd.input.value.trim() !== term) { return; }
            cmdRender(payload);
        }).catch(function (e) {
            if (e && e.name === 'AbortError') { return; }
            cmdNote(cmd.input.getAttribute('data-ss-error-text') || '');
        });
    }

    document.addEventListener('keydown', function (e) {
        // Ctrl/Cmd+K opens it from anywhere.
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            if (!cmdEls()) { return; }
            e.preventDefault();
            cmd.root.hidden ? cmdOpen(document.activeElement) : cmdClose();
            return;
        }

        if (!cmdEls() || cmd.root.hidden) { return; }

        if (e.key === 'Escape') { e.preventDefault(); cmdClose(); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); cmdActivate(cmd.active + 1); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); cmdActivate(cmd.active - 1); return; }
        if (e.key === 'Enter' && cmd.active >= 0 && cmd.items[cmd.active]) {
            e.preventDefault();
            cmd.items[cmd.active].click();
        }
    });

    document.addEventListener('input', function (e) {
        if (!cmdEls() || e.target !== cmd.input) { return; }
        clearTimeout(cmd.timer);
        cmd.timer = setTimeout(cmdSearch, 200);
    });

    document.addEventListener('click', function (e) {
        if (!cmdEls()) { return; }
        if (cmd.backdrop && e.target === cmd.backdrop) { cmdClose(); return; }
        // A result that opens a panel must close the palette first, or two dialogs are open at
        // once and focus belongs to neither.
        var item = e.target.closest && e.target.closest('.ss-command__item[data-ss-panel-open]');
        if (item) { cmdClose(); }
    });

    /* The sidebar's own search keeps filtering the NAVIGATION -- a different job, and merchants
       use it. Focusing it offers the palette; ignoring the offer changes nothing. */
    document.addEventListener('focusin', function (e) {
        if (e.target && e.target.id === 'sideBarsearch' && cmdEls() && cmd.root.hidden) {
            cmdOpen(e.target);
        }
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
        openCommandPalette: cmdOpen,
        closeCommandPalette: cmdClose,
        openPanel: openPanel,
        closePanel: closePanel,
        loadPanel: loadPanel,
        wireDirtyTracking: wireDirtyTracking
    };
})();
