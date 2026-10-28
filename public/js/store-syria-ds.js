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

    /* P2-01-19. A PANEL OPENED FROM INSIDE A BOOTSTRAP MODAL LOSES FOCUS TO IT, AND THAT IS
       MEASURED RATHER THAN SUSPECTED.

       Moving "Generate with AI" out of its second modal and into this panel left three things
       broken, and all three had ONE cause. Driven on a real create screen (Bootstrap 5.1.3):

         - openPanel() focuses the panel heading, and 0ms later `document.activeElement` is the
           MODAL's `.btn-close`, and still is at 50, 200 and 600ms;
         - Tab therefore cycles the modal: 10 of 10 presses landed outside the panel;
         - Escape reached the modal and closed the merchant's half-filled create form.

       The cause is `Modal.prototype._focustrap`: Bootstrap installs a `focusin` listener that pulls
       focus back inside the modal whenever it leaves, and the panel is deliberately a SIBLING of
       the modal rather than a child, which is the whole point of the row. So the trap is doing
       exactly its job on a surface it cannot know about.

       SO THE TRAP IS SUSPENDED WHILE A PANEL IS OPEN AND RESTORED WHEN IT CLOSES. Bootstrap 5.1
       offers no public API for that -- `focus: false` is construction-time only, and the modal here
       is the host theme's shared `#commanModel`, constructed by code this project does not own --
       so `_focustrap` is reached directly. That is a private field, and every assumption about it
       is GUARDED: no Bootstrap, no instance, no `_focustrap`, or no `deactivate` and this is a
       no-op that leaves today's behaviour exactly as it is. If a Bootstrap upgrade renames it, the
       panel does not break -- it goes back to losing focus, and
       `check-ai-panel-live.cjs` fails and says so.

       ONLY MODALS THAT ARE ACTUALLY OPEN are touched, and each one is remembered so that closing
       the panel restores precisely what it suspended rather than activating traps that were never
       on. */
    var suspendedTraps = [];

    function suspendModalFocusTraps() {
        suspendedTraps = [];

        try {
            if (!window.bootstrap || !window.bootstrap.Modal
                || typeof window.bootstrap.Modal.getInstance !== 'function') {
                return;
            }

            Array.prototype.forEach.call(document.querySelectorAll('.modal.show'), function (el) {
                var instance = window.bootstrap.Modal.getInstance(el);
                var trap = instance && instance._focustrap;

                if (trap && typeof trap.deactivate === 'function') {
                    trap.deactivate();
                    suspendedTraps.push(trap);
                }
            });
        } catch (e) {
            suspendedTraps = [];
        }
    }

    function restoreModalFocusTraps() {
        try {
            suspendedTraps.forEach(function (trap) {
                if (trap && typeof trap.activate === 'function') { trap.activate(); }
            });
        } catch (e) {}

        suspendedTraps = [];
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

        // BEFORE the focus move, not after: the trap fires on `focusin`, so suspending it
        // afterwards would let it steal the very focus this is about to set.
        suspendModalFocusTraps();

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

        // Restored BEFORE focus goes back, so the trigger -- which is typically inside the
        // modal -- is handed to a modal whose own trap is live again.
        restoreModalFocusTraps();

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
        containFocus(e, panel);
    });

    /* P2-01-20 -- ONE CONTAINMENT, TWO MODAL SURFACES.
       Extracted from the panel handler above rather than written again, because the command
       palette needs exactly this and a second trap would be a second thing to keep correct. */
    function containFocus(e, root) {
        var items = focusablesIn(root);
        if (!items.length) { return; }

        var first = items[0];
        var last = items[items.length - 1];

        if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    /* P2-01-20 -- THE COMMAND PALETTE DECLARED `aria-modal="true"` AND TRAPPED NOTHING.
       Measured with real Tab presses in all four configurations: of 25 presses with the palette
       open, 21 left it, landing on the page behind — which is still in the accessibility tree,
       because the palette neither marks the rest `aria-hidden` nor uses `inert`. A sighted mouse
       user never sees this; a keyboard user is dropped out of the dialog they opened.

       A panel on top wins, and is already handled by the listener above, so this bails when one
       is open rather than fighting it for the same keypress. */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') { return; }
        if (openPanelIds().length) { return; }

        var palette = document.getElementById('ss-command');
        if (!palette || palette.hidden) { return; }

        containFocus(e, palette);
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

    /* ---------------------------------------------- AJAX validation errors */

    /* F70. The product editor posts by AJAX, so a failed save never re-renders the
       page -- which means the server-rendered half of the error story (the summary
       component, the inline messages, and a collapsed <details> opening because one
       of its fields failed) could never fire. All the client got back was
       `{flag, msg}`: one message, no field names. ProductController now also returns
       `errors` keyed by field, and this applies it.

       WHY THE SECTION-OPENING IS THE POINT. A merchant whose tax id is rejected was
       being shown a toast while the box holding the tax id stayed shut. Marking the
       field is not enough on its own, because the field is not on screen.

       KEY SHAPES. Laravel keys the bag with dots -- `options.0.price` -- while the
       control is named `options[0][price]`. Both spellings are tried, and so is the
       bare id, because this editor names some controls by id only.

       TEXT IS SET WITH textContent, NEVER innerHTML. These strings come back from the
       server, and several of them interpolate merchant-entered values. */

    var SS_ERR_NOTE = 'ss-invalid-note';

    function errorKeyToSelectors(key) {
        var bracket = String(key).replace(/\.(\d+)\./g, '[$1][').replace(/\.(\w+)$/, '[$1]');
        var parts = String(key).split('.');
        var out = [];
        var push = function (sel) { if (out.indexOf(sel) === -1) { out.push(sel); } };

        push('[name="' + key + '"]');
        if (bracket !== key) { push('[name="' + bracket + '"]'); }
        // `tax_id` in the bag, `tax_id[]` in the markup
        push('[name="' + parts[0] + '[]"]');
        push('[name="' + key + '[]"]');
        // and some controls here are addressable only by id
        push('#' + String(key).replace(/\./g, '_'));
        return out;
    }

    function findControl(key, root) {
        var selectors = errorKeyToSelectors(key);
        for (var i = 0; i < selectors.length; i++) {
            var el;
            try { el = (root || document).querySelector(selectors[i]); } catch (e) { el = null; }
            if (el) { return el; }
        }
        return null;
    }

    function clearFormErrors(root) {
        var scope = root || document;
        Array.prototype.forEach.call(scope.querySelectorAll('.is-invalid'), function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
        });
        Array.prototype.forEach.call(scope.querySelectorAll('.' + SS_ERR_NOTE), function (el) {
            el.parentNode.removeChild(el);
        });
        Array.prototype.forEach.call(scope.querySelectorAll('[data-ss-advanced-error]'), function (el) {
            el.removeAttribute('data-ss-advanced-error');
            var badge = el.querySelector('.ss-advanced__badge');
            if (badge && badge.getAttribute('data-ss-generated') === '1') {
                badge.parentNode.removeChild(badge);
            }
        });
        var summary = scope.querySelector('[data-ss-error-summary]');
        if (summary && summary.getAttribute('data-ss-generated') === '1') {
            summary.parentNode.removeChild(summary);
        }
    }

    /* `errors` is `{field: [message, ...]}` or `{field: message}`. `opts.form` scopes
       the work to one form; `opts.needsAttentionText` is the badge wording, passed in
       because this file has no translator. */
    function applyFormErrors(errors, opts) {
        opts = opts || {};
        var form = opts.form
            ? (typeof opts.form === 'string' ? document.querySelector(opts.form) : opts.form)
            : document.querySelector('form[data-ss-dirty-track]') || document.querySelector('form');
        if (!errors || typeof errors !== 'object') { return 0; }

        clearFormErrors(form || document);

        var keys = Object.keys(errors);
        var items = [];
        var firstControl = null;

        keys.forEach(function (key, index) {
            var list = errors[key];
            var message = Array.isArray(list) ? list[0] : list;
            if (!message) { return; }

            var control = findControl(key, form || document);
            items.push({ key: key, message: String(message), control: control });

            if (!control) { return; }
            if (!firstControl) { firstControl = control; }

            control.classList.add('is-invalid');
            control.setAttribute('aria-invalid', 'true');

            /* The inline message, next to the control. Placed after the control's
               wrapper when the control itself is hidden by a widget (Choices and
               select2 both set display:none on the native element), so the note is
               not invisible alongside it. */
            var noteId = 'ss-err-' + index + '-' + String(key).replace(/[^\w-]/g, '_');
            var note = document.createElement('div');
            note.className = SS_ERR_NOTE + ' invalid-feedback d-block';
            note.id = noteId;
            note.textContent = String(message);
            var host = control;
            var wrapped = control.closest('.choices') || control.closest('.select2-container');
            if (wrapped) { host = wrapped; }
            if (host.parentNode) { host.parentNode.insertBefore(note, host.nextSibling); }
            control.setAttribute('aria-describedby', noteId);

            /* AND OPEN THE SECTION IT LIVES IN. This is F70's actual requirement. */
            var details = control.closest('details');
            if (details) {
                details.setAttribute('open', '');
                details.setAttribute('data-ss-advanced-error', 'true');
                var summaryEl = details.querySelector('summary');
                if (summaryEl && !summaryEl.querySelector('.ss-advanced__badge')) {
                    var badge = document.createElement('span');
                    badge.className = 'ss-advanced__badge';
                    badge.setAttribute('data-ss-generated', '1');
                    badge.textContent = opts.needsAttentionText || 'Needs attention';
                    summaryEl.appendChild(badge);
                }
            }
        });

        if (!items.length) { return 0; }

        /* The summary. Reuses the server-rendered container when one is present, and
           builds the same markup when it is not -- which on this editor is always,
           because the component renders nothing when the bag is empty. */
        var summary = (form || document).querySelector('[data-ss-error-summary]');
        if (!summary) {
            summary = document.createElement('div');
            summary.className = 'ss-errorsummary';
            summary.setAttribute('role', 'alert');
            summary.setAttribute('tabindex', '-1');
            summary.setAttribute('data-ss-error-summary', '');
            summary.setAttribute('data-ss-generated', '1');
            if (form && form.firstChild) { form.insertBefore(summary, form.firstChild); }
            else if (form) { form.appendChild(summary); }
        }
        while (summary.firstChild) { summary.removeChild(summary.firstChild); }

        var title = document.createElement('h2');
        title.className = 'ss-errorsummary__title';
        title.textContent = opts.titleText
            || (items.length === 1
                ? 'There is a problem with 1 field'
                : 'There are problems with ' + items.length + ' fields');
        summary.appendChild(title);

        var list = document.createElement('ul');
        list.className = 'ss-errorsummary__list';
        items.forEach(function (item) {
            var li = document.createElement('li');
            if (item.control && item.control.id) {
                var a = document.createElement('a');
                a.className = 'ss-errorsummary__link';
                a.setAttribute('href', '#' + item.control.id);
                a.textContent = item.message;
                li.appendChild(a);
            } else {
                li.textContent = item.message;
            }
            list.appendChild(li);
        });
        summary.appendChild(list);

        if (summary.scrollIntoView) { summary.scrollIntoView({ block: 'center' }); }
        if (summary.focus) { summary.focus(); }
        else if (firstControl && firstControl.focus) { firstControl.focus(); }

        return items.length;
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

    /* P2-06-7. The shortcuts are the PRE-SEARCH state: they show while the palette has no
       results and step aside the moment one arrives, so the dialog never shows two competing
       lists. They are server-rendered, so hiding them is purely presentational -- nothing here
       decides what a merchant may see. */
    function cmdShortcuts(visible) {
        var box = document.querySelector('[data-ss-command-shortcuts]');
        if (box) { box.hidden = !visible; }
    }

    function cmdNote(text) {
        cmdShortcuts(true);
        // No selection means no actions: a bar left behind would offer the previous result's
        // action while the list beside it says "Nothing found".
        cmdActions(null);
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
        cmdShortcuts(false);
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

                /* NOT TABBABLE, and this is a correctness fix rather than a preference. These are
                   `<a href>` elements with `role="option"`, so they were natively in the tab
                   order -- and an `aria-activedescendant` combobox requires DOM FOCUS TO STAY ON
                   THE INPUT. Tabbing into an option moved real focus while `aria-activedescendant`
                   still pointed at whichever row was selected, so a screen reader was told two
                   contradictory things about what was current. Review reproduced it.

                   An `option` is also not a tab stop in the listbox pattern: arrow keys move the
                   selection, which is exactly what this dialog already does. Making them -1 also
                   puts the action bar immediately after the input in the tab order instead of
                   sixteen stops later. */
                a.tabIndex = -1;
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

                /* P2-06-8. The actions ride ON the option element rather than in a parallel
                   array, so they cannot drift out of step with the selection index. They are
                   NOT rendered inside the row -- see `cmdActions()`. */
                a.ssActions = Array.isArray(item.actions) ? item.actions : [];
                a.ssTitle = title.textContent;

                li.appendChild(a);
                cmd.list.appendChild(li);
                cmd.items.push(a);
            });
        });

        cmd.input.setAttribute('aria-expanded', cmd.items.length ? 'true' : 'false');
        cmd.status.textContent = String(cmd.items.length);
        if (cmd.items.length) { cmdActivate(0); }
    }

    /**
     * P2-06-8 -- the actions for the SELECTED result, rendered OUTSIDE the listbox.
     *
     * AND OUTSIDE IS THE WHOLE POINT, not a layout preference. The results are a `listbox` and
     * each row is an `option`; an `option` is a LEAF in ARIA and must not contain interactive
     * descendants, so putting buttons in a row would break the one pattern P2-06-2 was careful
     * to get right -- `aria-activedescendant` moving a selection while the caret stays in the
     * input. A separate group keeps that intact and gives one predictable place for the actions
     * instead of a control that moves with the cursor.
     *
     * THE GROUP NAMES ITS SUBJECT. `aria-label` is "Actions for <result>", so a user who Tabs
     * into it from the input lands somewhere self-describing rather than on a bare "Edit".
     *
     * IT DECIDES NOTHING. The server sent exactly the actions this principal may perform; an
     * empty list renders an empty bar. Nothing here is filtered, hidden or re-checked, because a
     * client-side filter would mean the full action list had been sent to every caller.
     */
    function cmdActions(option) {
        var box = document.getElementById('ss-command-actions');
        if (!box) { return; }

        box.innerHTML = '';

        var actions = (option && option.ssActions) || [];

        if (!actions.length) {
            box.hidden = true;
            box.removeAttribute('aria-label');
            return;
        }

        box.hidden = false;
        box.setAttribute('aria-label',
            (box.getAttribute('data-ss-actions-label') || 'Actions') + ': ' + (option.ssTitle || ''));

        /* THE SELECTION CHANGE IS ANNOUNCED, which review found it was not. `aria-activedescendant`
           makes a reader announce the OPTION as the selection moves, and says nothing about the
           actions that moved with it -- so a merchant arrowing down had no way to know an action
           had appeared, changed or gone. The count goes into the palette's own existing status
           region rather than a new one: two live regions for one dialog compete. */
        if (cmd.status) {
            cmd.status.textContent = (option.ssTitle || '') + ' — '
                + actions.length + ' '
                + (box.getAttribute('data-ss-actions-label') || 'Actions');
        }

        actions.forEach(function (action) {
            var a = document.createElement('a');
            a.className = 'ss-command__action';
            a.href = action.href || '#';
            a.textContent = action.label == null ? '' : String(action.label);

            /* An action may open a PANEL instead of navigating -- the order preview does, and it
               reuses the exact contract the result row already uses (`data-ss-panel-open` /
               `data-ss-panel-url`) rather than a second mechanism for the same job. Without this
               the server's `panel` key was silently ignored and the action became a full page
               load to a read-only surface meant to open in place. */
            if (action.panel) {
                a.setAttribute('data-ss-panel-open', action.panel);
                a.setAttribute('data-ss-panel-url', action.href || '');
            }

            box.appendChild(a);
        });
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
        cmdActions(el);
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

        /* P2-06-8 FIX. THIS HANDLER IS BOUND TO `document` AND HAD NO TARGET GUARD, which was
           harmless while the dialog held only an input and a listbox and became three defects the
           moment the action bar gave it a second focusable region:

             - Enter on a focused action ran `cmd.items[cmd.active].click()` -- activating the
               selected ROW instead of the action the merchant had tabbed to and was looking at;
             - ArrowUp/ArrowDown moved the row selection, which re-renders the bar from
               `cmdActions()`, so the focused element was removed from the DOM mid-keypress and
               focus fell to `<body>` -- outside the `aria-modal` dialog entirely;
             - both were reproduced in a browser by review, not inferred.

           Escape stays global on purpose: closing the dialog is the one action that must work
           from anywhere inside it. Everything else belongs to the input. */
        var inActions = e.target && e.target.closest
            && e.target.closest('#ss-command-actions');

        if (e.key === 'Escape') { e.preventDefault(); cmdClose(); return; }

        if (inActions) { return; }

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

    /* ----------------------------------------------- table preferences (P2-06-4)

       WHAT A MERCHANT KEEPS BETWEEN VISITS: how many rows per page, how the
       table is sorted, and which columns are showing. Those are PREFERENCES --
       a merchant who works 50 rows at a time said so once and should not have
       to say it on every screen, every day.

       WHAT IS DELIBERATELY NOT KEPT: the search term, and the page number.
       DataTables' own `stateSave` restores both, and that is the one thing
       that turns this feature into a support ticket: a merchant who searched
       "refund" last Thursday comes back to a table showing four rows out of
       nine hundred, with the box pre-filled somewhere they are not looking,
       and concludes their orders are gone. Restoring page 7 of a list whose
       rows have since changed is the same mistake more quietly. So the state
       is sanitised on the way OUT and again on the way IN -- in, as well,
       because a state saved by an earlier build (or by any other code that
       sets stateSave) is still sitting in localStorage and must not be
       honoured.

       NO SERVER STORAGE, AND THAT IS A SCOPE DECISION, not an oversight.
       Per-device presentation state needs no table and no migration, and this
       phase is not authorised to add one. What is stored is a row count, a
       sort direction and a set of visibility booleans -- NO tenant data, no
       customer data, no identifiers -- which is also why sharing the default
       `DataTables_<id>_<path>` key between two merchants on one browser is a
       preference wrinkle and not a disclosure. Cross-device persistence, if it
       is ever wanted, is a separate decision with a schema behind it.

       DataTables 1.10.20 discards a saved state whose column COUNT differs
       from the table's (`_fnLoadState` bails on `b.columns.length !== h.length`),
       so enabling or disabling a module cannot leave a table applying the
       wrong column's visibility. Verified in the shipped
       `public/vendor/datatables/datatables.min.js`, not assumed. */

    function sanitizeTableState(data) {
        if (!data || typeof data !== 'object') { return data; }

        // The global search box.
        if (data.search && typeof data.search === 'object') {
            data.search.search = '';
        }

        // Always open on the first page.
        data.start = 0;

        // And the per-column filters, which `columns.search` carries separately
        // from the global one.
        if (Object.prototype.toString.call(data.columns) === '[object Array]') {
            data.columns.forEach(function (column) {
                if (column && column.search && typeof column.search === 'object') {
                    column.search.search = '';
                }
            });
        }

        return data;
    }

    /* ------------------------------------------- proxy click (P2-03-3)

       A SECOND CONTROL FOR ONE ACTION, WITHOUT A SECOND IMPLEMENTATION.

       The product editor's Save is an anchor in the page header
       (`#submit-all`), and `product/create.blade.php` binds ~120 lines of
       FormData assembly to its click. MEASURED, which is why the sticky bar
       exists at all: with the form scrolled to the bottom that control sits at
       y = -1175 at 1440px and y = -3131 at 390px -- entirely off-screen, so a
       merchant who has just filled the form cannot see or reach Save without
       scrolling back to the top.

       The sticky bar's button therefore does not submit. It CLICKS THE EXISTING
       CONTROL, so there is exactly one submit path and the two can never drift:
       a change to the handler reaches both, and a bug in it is one bug. Copying
       the assembly into a second handler is the "parallel engine" the brief
       forbids, in miniature.

       `preventDefault` because the proxy is itself an anchor; the guard against
       proxying to itself is there because `data-ss-click-proxy="#submit-all"`
       on `#submit-all` would otherwise recurse until the stack gave out. */

    function wireClickProxy(node) {
        if (node.hasAttribute('data-ss-click-proxy-wired')) { return; }
        node.setAttribute('data-ss-click-proxy-wired', 'true');

        node.addEventListener('click', function (event) {
            var selector = node.getAttribute('data-ss-click-proxy');
            if (!selector) { return; }

            var target = document.querySelector(selector);
            if (!target || target === node) { return; }

            event.preventDefault();
            target.click();
        });
    }

    /* --------------------------------------------------- row density (P2-03-10)

       THE HALF OF P2-03-1 THAT WAS NOT DONE. The merchant lists are 55px per
       row at every width with no way to change it -- MEASURED at 1440, 1280,
       992, 768 and 390, all five identical.

       WHAT SETS THE FLOOR, measured rather than assumed. 55px is not the text:
       the cell is 14px/21px type with 11.2px of block padding, which comes to
       43.4px. The extra 11.6 is the ROW ACTIONS -- a 32px control group in the
       last cell (`span.d-flex`), on the order list as well as the product list,
       and nothing in either row is taller. So the row height is
       `32 + 2 * padding-block`, and the only honest way to compact it is to cut
       the padding and leave the control alone:

           comfortable   32 + 2 * 11.2  = 54.4  -> 55px   (the theme's own value)
           compact       32 + 2 * 4     = 40px

       That is a 27% reduction, and the 32px control is untouched -- still well
       past the 24px touch target the acceptance asks for, with 8px to spare.
       Shrinking the control to buy more would trade the requirement for rows.

       THE CHOICE RIDES THE STATE THAT ALREADY EXISTS. P2-06-4 gave every table
       `stateSave` with `stateDuration: 0` (localStorage, no expiry), and
       DataTables persists whatever keys are on the state object -- its default
       `stateSaveCallback` is `JSON.stringify(data)`. So density is one more key
       beside the column set and the page length, stored and restored by the
       same mechanism, rather than a second preference store with its own bugs.

       COMFORTABLE IS THE ABSENCE OF THE ATTRIBUTE, not a value of it. The
       stylesheet only has a rule for `compact`, so a table that has never been
       touched -- and a page where this script does not run at all -- renders
       exactly as it did before. */

    var DENSITIES = ['comfortable', 'compact'];

    /* THE AUTHORITATIVE VALUE, per table, keyed by the table's id -- one page can
       carry several tables (the dashboard carries two) and a single variable
       would give the second one the first one's choice.

       THE KEYING IS NOT OBSERVABLE TODAY, and the first version of this comment
       justified it with a table that does not exist -- "the dashboard carries
       two". Measured by review: `/dashboard` carries ZERO helper-built
       DataTables; its two `table.dataTable` elements are hand-written, with no
       id and no wrapper. No view in the repository renders two `<x-datatable>`.
       The map is still right, but for the reason below and not that one.

       IT IS THE SOURCE OF TRUTH, AND THE DOM IS ITS PROJECTION. This started out
       as a one-shot stash that `stateSaveParams` ignored in favour of reading the
       attribute off the container, and that was a real bug the browser check
       caught: DataTables saves state DURING init, BEFORE `initComplete` runs, so
       the container had no `data-ss-density` yet, `compact` read back as
       `comfortable`, and the save clobbered the stored choice. The DOM looked
       right for that one page view -- the attribute was applied a moment later --
       and the NEXT reload came back comfortable, which is the shape of bug that
       survives a careless manual test. Reading the map first removes the ordering
       hazard instead of trying to sequence around it. */
    var densityState = {};

    function densityKey(api) {
        try {
            var node = api.table().node();
            return (node && node.id) || '';
        } catch (e) { return ''; }
    }

    function densityHost(api) {
        try {
            return api.table().container() || null;
        } catch (e) { return null; }
    }

    function currentDensity(host) {
        if (!host) { return 'comfortable'; }
        var v = host.getAttribute('data-ss-density');
        return DENSITIES.indexOf(v) === -1 ? 'comfortable' : v;
    }

    function applyDensity(host, density) {
        if (!host) { return; }
        if (DENSITIES.indexOf(density) === -1) { density = 'comfortable'; }
        host.setAttribute('data-ss-density', density);

        /* The control is a toggle, so it carries its own state for a screen
           reader. `aria-pressed` and not a class: the pressed look is the
           stylesheet's business, the pressed FACT is the button's.
           `role="button"` is set with it -- see `wireDensityButton` for why that
           is required rather than optional here. */
        var btn = host.querySelector('.ss-density');
        if (btn) {
            btn.setAttribute('aria-pressed', density === 'compact' ? 'true' : 'false');
        }
    }

    /* stateSaveParams: put the authoritative value on the object DataTables is
       about to store -- the map first, the DOM only as a fallback for a table
       this module never initialised. See the note on `densityState` for why the
       order matters. */
    function saveDensityState(settings, data) {
        if (!data || typeof data !== 'object') { return data; }
        try {
            var api = new window.jQuery.fn.dataTable.Api(settings);
            var k = densityKey(api);
            var known = k ? densityState[k] : null;
            data.ssDensity = DENSITIES.indexOf(known) !== -1
                ? known
                : currentDensity(densityHost(api));
        } catch (e) { /* a table built outside the admin layout */ }
        return data;
    }

    /* stateLoadParams: DataTables hands us the stored object BEFORE the table is
       built, so the value is stashed and applied by initComplete -- the
       container does not exist yet at this point. */
    function loadDensityState(settings, data) {
        if (!data || typeof data !== 'object') { return data; }
        try {
            var api = new window.jQuery.fn.dataTable.Api(settings);
            var k = densityKey(api);
            if (k && DENSITIES.indexOf(data.ssDensity) !== -1) {
                densityState[k] = data.ssDensity;
            }
        } catch (e) { /* as above */ }
        return data;
    }

    /* THE PROMISE `aria-pressed` MAKES, KEPT.

       DataTables Buttons 1.3.1 renders every button as an `<a href="#">` and
       binds only `click` and `keyup` on Enter. MEASURED on the rendered control
       by independent review: its computed role is `link`, so
       `getByRole('button', {pressed:false})` matches NOTHING -- the pressed
       state was announced to no one, and `aria-pressed` is not even a permitted
       attribute on `role=link`. Pressing Space scrolled the page by 400px
       instead of toggling.

       So the state was a promise the control did not keep, which is the F38/F39
       lesson one level up: a name or a state that looks set and is not is worse
       than none. `role="button"` makes the role match the behaviour, and the
       Space handler supplies the keystroke an anchor does not fire on its own.
       (P2-03-1 deliberately did NOT set `role="button"` on the column-visibility
       button, and that was right: it is a one-shot menu trigger with no state to
       convey and no Space contract to keep. The difference is `aria-pressed`.)

       AND FOCUS IS RETURNED. Buttons calls `blur()` on its own control inside
       the click handler, so after every toggle focus landed on `<body>` --
       measured -- which loses the state change for a screen-reader user and
       makes them traverse the page again to toggle back. */
    function wireDensityButton(host) {
        if (!host) { return; }

        var btn = host.querySelector('.ss-density');
        if (!btn || btn.hasAttribute('data-ss-density-wired')) { return; }
        btn.setAttribute('data-ss-density-wired', 'true');
        btn.setAttribute('role', 'button');

        btn.addEventListener('keydown', function (event) {
            if (event.key === ' ' || event.key === 'Spacebar' || event.keyCode === 32) {
                event.preventDefault();
                btn.click();
            }
        });
    }

    function initTableDensity(api) {
        var host = densityHost(api);
        if (!host) { return; }
        var k = densityKey(api);
        var density = (k && densityState[k]) || 'comfortable';
        if (k) { densityState[k] = density; }
        wireDensityButton(host);
        applyDensity(host, density);
    }

    function toggleTableDensity(api) {
        var host = densityHost(api);
        if (!host) { return; }
        var k = densityKey(api);
        var now = (k && densityState[k]) || currentDensity(host);
        var next = now === 'compact' ? 'comfortable' : 'compact';
        if (k) { densityState[k] = next; }
        applyDensity(host, next);

        /* Buttons blurs its own control inside the click handler, so without
           this a keyboard user is dropped on <body> after every toggle and
           never hears the state change.

           DEFERRED BY A TICK, and that is measured rather than stylistic: a
           synchronous focus() here ran BEFORE Buttons' blur() and was simply
           undone -- the check reported `activeElement=theme-3`, i.e. <body>,
           with the focus call in place. Queuing it puts it after. */
        var btn = host.querySelector('.ss-density');
        if (btn) {
            window.setTimeout(function () {
                try { btn.focus(); } catch (e) { /* detached */ }
            }, 0);
        }

        /* Persist immediately. Without this the choice survives only until the
           next event that happens to save state, and a reload would lose it --
           which is the one thing the acceptance asks for by name. */
        try { api.state.save(); } catch (e) { /* no state on this table */ }
    }

    /* ------------------------------------------- notification badge (P2-06-3)

       ONE FETCH, ONE NUMBER. The bell is a plain link to the notifications
       page; this only fills the unread count on it, so the merchant can see
       there is something to look at without opening the page.

       NO POLLING. An interval on every admin page is a request per merchant
       per tab per interval, forever, for a number that changes when an order
       arrives -- which the merchant will see on the next page they load
       anyway. If live updates are ever wanted, that is a push channel (the
       Firebase path StoreNotificationService already uses for the app), not a
       timer added quietly here.

       THE LABEL IS BUILT FROM THE MARKUP'S OWN TRANSLATED TEXT. This file has
       no translator, so the screen-reader label appends the count to the
       server-rendered word rather than composing an English sentence -- the
       same reason the panel takes its loading text as a prop.

       EVERY FAILURE IS SILENT. A 403, a network error, a logged-out session:
       the badge simply stays hidden, which is what it looks like with nothing
       unread. A bell that renders an error is worse than a bell that renders
       nothing. */

    function updateNotificationBadge() {
        var bell = document.querySelector('[data-ss-notification-bell]');
        if (!bell) { return; }

        var url = bell.getAttribute('data-ss-feed-url');
        var badge = bell.querySelector('[data-ss-notification-count]');
        var label = bell.querySelector('[data-ss-notification-label]');
        if (!url || !badge) { return; }

        var base = label ? (label.textContent || '').trim() : '';

        fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }

                var unread = parseInt(data.unread, 10);
                if (!(unread > 0)) { return; }

                /* `textContent`, never innerHTML. The number comes from the
                   server, but the rule in this file is that nothing fetched is
                   ever parsed as markup -- the command palette is written the
                   same way, for the same reason. */
                badge.textContent = unread > 99 ? '99+' : String(unread);
                badge.hidden = false;

                if (label && base) { label.textContent = base + ' (' + unread + ')'; }
            })
            .catch(function () { /* see the note above: silent by design */ });
    }

    /* ------------------------------------------------------------------ init */

    function init() {
        Array.prototype.forEach.call(
            document.querySelectorAll('form[data-ss-dirty-track]'),
            wireDirtyTracking
        );
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-ss-click-proxy]'),
            wireClickProxy
        );
        focusErrorSummary();
        updateNotificationBadge();
        // P2-06-7. Delegated on the document, so it is wired once and survives re-render.
        wireFavouriteToggle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* A tiny public surface, so a screen can drive a panel from its own
       script -- the orders list opens the preview panel after its fetch
       resolves, for instance. */

    /* ------------------------------------------------- column reorder (P2-06-6)

       COLREORDER 1.5.4 HAS NO KEYBOARD PATH AT ALL. Measured, not assumed: the
       file binds `mousedown` and `touchstart` and contains zero occurrences of
       `keydown`, `keyup`, `keypress`, `tabindex` or `aria-`. So the feature it
       provides is reachable by pointer only, which for the function "reorder a
       column" is a WCAG 2.1.1 failure outright, and a 2.5.7 one for requiring a
       dragging movement with no single-pointer alternative.

       THE EXTENSION IS NOT PATCHED. It is vendored unmodified, with its licence,
       so an upgrade is a file swap. The keyboard path is built HERE, on the
       `colReorder.move()` API the extension already registers, so the two never
       disagree about the order — there is one mover and the keyboard calls it.

       Alt+Arrow, not a bare arrow: the header is already a sort control that
       responds to Enter and Space, and bare arrows scroll the table region. Alt
       is free and is the modifier the host's own shortcuts leave alone.

       A MOVE IS ANNOUNCED, or a keyboard user has no way to know it happened —
       the only feedback the drag path gives is visual. The live region is the
       one the design system already owns. */
    function reorderableHeaders(table) {
        var api = window.jQuery && window.jQuery.fn.dataTable
            && window.jQuery.fn.dataTable.isDataTable(table)
            ? window.jQuery(table).DataTable() : null;
        if (!api) { return null; }

        var settings = api.settings()[0];
        var cr = settings && settings._colReorder;
        if (!cr) { return null; }

        return {
            api: api,
            /* The held columns, read from ColReorder's OWN state rather than from
               a copy of the option: `s.fixed` is the leading count and
               `s.fixedRight` the trailing one, and those are what the drag path
               honours. Reading the same values means the keyboard path cannot
               permit a move the mouse path forbids — which for the action column
               is the whole guard. */
            first: cr.s.fixed,
            last: api.columns().count() - 1 - cr.s.fixedRight
        };
    }

    /* ONE POLITE LIVE REGION, shared. It was `announceColumnMove` while the column keys were
       its only caller; P2-06-7's favourite toggle needs the same thing, and two live regions on a
       page compete -- a screen reader may announce either, in either order. Renamed rather than
       copied. */
    function announceLive(message) {
        var region = document.getElementById('ss-live-region');
        if (!region) {
            region = document.createElement('div');
            region.id = 'ss-live-region';
            region.className = 'ss-visually-hidden';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }
        region.textContent = '';
        // A repeated identical string is not re-announced; the blank frame is
        // what makes two moves in the same direction both audible.
        window.setTimeout(function () { region.textContent = message; }, 50);
    }

    /**
     * P2-06-7 -- the favourite toggle.
     *
     * DELEGATED ON THE DOCUMENT, because the buttons are re-rendered by the response and a
     * listener bound to each one would be lost on the first toggle.
     *
     * THE SERVER DECIDES, THE BUTTON REFLECTS. The response says whether the page is now a
     * favourite; the button is set from that answer rather than flipped optimistically, so a
     * refusal (a page the employee may no longer open) leaves the control showing the truth
     * instead of a state the server rejected.
     */
    function wireFavouriteToggle() {
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-ss-favourite]') : null;
            if (!btn) { return; }

            e.preventDefault();

            var meta = document.querySelector('[data-ss-favourite-url]');
            if (!meta) { return; }

            var token = document.querySelector('meta[name="csrf-token"]');

            /* P2-01-15 -- THE EXECUTING STATE IS ANNOUNCED, not only drawn. `[disabled]` already
               gives a visible one (`.ss-command__star[disabled]` sets `cursor: progress` and
               dims it), but a screen-reader user got nothing at all between the click and the
               answer. `aria-busy` states it, and the shared live region says it once. */
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');

            var busyText = meta.getAttribute('data-ss-favourite-busy');
            if (busyText) { announceLive(busyText); }

            window.fetch(meta.getAttribute('data-ss-favourite-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
                },
                body: JSON.stringify({ page: btn.getAttribute('data-ss-favourite') })
            }).then(function (r) {
                return r.json().then(function (body) { return { ok: r.ok, body: body }; });
            }).then(function (res) {
                if (!res.ok) { throw new Error('refused'); }

                var on = !!res.body.favourited;
                btn.classList.toggle('is-on', on);
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');

                /* THE SUCCESS STATE WAS INCOMPLETE AND PART OF IT WAS WRONG. Flipping `is-on`
                   changes `color` to the brand, and colour alone is not an accessible
                   distinction -- so the glyph is swapped too. And the `title` and the
                   visually-hidden label were left as rendered, so after adding a page a screen
                   reader still read "Add :page to favourites": the accessible name said the
                   opposite of the state beside it. Every affordance is moved from the copy the
                   markup carries, so this function still holds none of it. */
                var glyph = btn.querySelector('[data-ss-fav-glyph]');
                if (glyph) { glyph.textContent = on ? '\u2605' : '\u2606'; }

                var title = btn.getAttribute(on ? 'data-ss-fav-title-on' : 'data-ss-fav-title-off');
                if (title) { btn.setAttribute('title', title); }

                var hidden = btn.querySelector('[data-ss-fav-label]');
                var label = btn.getAttribute(on ? 'data-ss-fav-label-on' : 'data-ss-fav-label-off');
                if (hidden && label) { hidden.textContent = label; }

                var visible = btn.querySelector('[data-ss-fav-text]');
                var text = btn.getAttribute(on ? 'data-ss-fav-text-on' : 'data-ss-fav-text-off');
                if (visible && text) { visible.textContent = text; }

                if (res.body.message) { announceLive(res.body.message); }
            }).catch(function () {
                announceLive(meta.getAttribute('data-ss-favourite-error') || '');
            }).then(function () {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
            });
        });
    }

    function wireColumnReorderKeys(table) {
        if (!table || table.getAttribute('data-ss-colreorder-keys') === 'on') { return; }
        table.setAttribute('data-ss-colreorder-keys', 'on');

        table.addEventListener('keydown', function (e) {
            if (!e.altKey || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) { return; }

            var th = e.target && e.target.closest ? e.target.closest('th') : null;
            if (!th || th.closest('table') !== table) { return; }

            var ctx = reorderableHeaders(table);
            if (!ctx) { return; }

            /* THE HEADER'S COLUMN INDEX COMES FROM THE API, NOT FROM ITS DOM POSITION, and
               the difference is a real off-by-one rather than pedantry: the hidden `id` column
               is column 0 and renders NO `<th>` at all, so the twelve headers in the DOM are
               columns 1..12. Measured — with `indexOf()` the first reorderable header resolved
               to the wrong column, the ACTION header became focusable, and `move()` was handed
               indexes that moved nothing. `column(node).index()` is DataTables' own mapping and
               cannot drift from it. */
            var from = ctx.api.column(th).index();
            if (typeof from !== 'number' || from < ctx.first || from > ctx.last) { return; }

            /* VISUAL DIRECTION, NOT INDEX DIRECTION. In RTL the leftward arrow
               moves a column to a HIGHER index, because the first column paints
               on the right. Without this the keys would work in English and feel
               reversed in Arabic — the same class of defect as a hard-coded
               `right` in CSS. */
            var rtl = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
            var forward = (e.key === 'ArrowRight') !== rtl;
            var to = forward ? from + 1 : from - 1;

            if (to < ctx.first || to > ctx.last) { return; }

            e.preventDefault();

            /* `drop = true` MARKS THIS A FINAL REORDER, NOT A LIVE ONE, and the state is saved
               BY HAND because the extension's own API does not do it. Measured, on a clean
               profile: `move(from, to)` and `move(from, to, true)` both reorder the header and
               both leave the saved `ColReorder` array at the identity order, so the move was
               lost on the next page load. The extension's mouse path does it in two steps --
               `fnColReorder(from, to, true)` then `oApi._fnSaveState(...)` (dataTables.colReorder
               1.5.4 lines 1109 and 1119) -- and `colReorder.move()` is only the first of them.
               `state.save()` is the public spelling of the second, and it is what makes
               `stateSave` carry a keyboard move the way it already carries a drag. */
            ctx.api.colReorder.move(from, to, true);
            ctx.api.state.save();

            /* Focus follows the ELEMENT, not the position — otherwise a second keypress would
               move whichever column had slid into the old slot. The node is re-read after the
               move because ColReorder rebuilds the header row. */
            var moved = ctx.api.column(to).header();
            if (moved) {
                if (!moved.hasAttribute('tabindex')) { moved.setAttribute('tabindex', '0'); }
                moved.focus();
            }

            /* POSITION AMONG THE MOVABLE COLUMNS, not the raw column index. The first version
               announced `to + 1` out of `ctx.last + 1`, which read as "3 / 12" on a table with
               thirteen columns of which ten can move — two numbers that describe neither the
               table nor the range the user is moving within. Here both sides count the same
               thing. */
            var span = ctx.last - ctx.first + 1;

            announceLive(
                (th.textContent || '').trim() + ': ' + (to - ctx.first + 1) + ' / ' + span
            );
        });

        /* The headers have to be reachable before they can be driven. ColReorder
           adds no tabindex of its own, and a `th` is not focusable by default. */
        var ctx = reorderableHeaders(table);
        if (!ctx) { return; }

        /* Focusable only where a move is actually permitted, and keyed on the COLUMN index for
           the same reason as above. Making the action header focusable would promise a
           keyboard affordance that then refuses to do anything. */
        ctx.api.columns().every(function () {
            var i = this.index();
            var header = this.header();

            /* VISIBLE columns only. `column(i).header()` returns a node for a HIDDEN column too
               -- the hidden `id` column has one, detached from the rendered header row -- and
               giving that a `tabindex` would promise a focus target that does not exist.

               The guard is ours to keep; the `tabindex` the live check saw on column 0 is NOT.
               DataTables core puts one on every SORTABLE column's header, hidden or not
               (`!1!==f.bSortable&&(d.attr("tabindex",a.iTabIndex)...)`, `iTabIndex: 0`), which
               is why the checkbox and action headers have none -- both are `bSortable: false`.
               This loop could not have set it either way: it starts at `s.fixed`, which is 2 on
               the product table. */
            if (!this.visible()) { return; }

            if (header && i >= ctx.first && i <= ctx.last && !header.hasAttribute('tabindex')) {
                header.setAttribute('tabindex', '0');
            }
        });
    }

    window.StoreSyriaDS = {
        openCommandPalette: cmdOpen,
        closeCommandPalette: cmdClose,
        openPanel: openPanel,
        closePanel: closePanel,
        loadPanel: loadPanel,
        wireDirtyTracking: wireDirtyTracking,
        // P2-03-3. One action, two controls, one implementation.
        wireClickProxy: wireClickProxy,
        // P2-06-7. The favourite toggle, delegated once on the document.
        wireFavouriteToggle: wireFavouriteToggle,
        // P2-06-4. Called from the `stateSaveParams`/`stateLoadParams` options
        // that `buildDataTable()` puts on every merchant table, so the rule
        // lives in one place rather than inlined 68 times.
        sanitizeTableState: sanitizeTableState,
        // P2-03-10. The four hooks `buildDataTable()` wires for row density, so
        // the rule lives here with its measurements rather than inlined.
        tableDensity: {
            save: saveDensityState,
            load: loadDensityState,
            init: initTableDensity,
            toggle: toggleTableDensity,
            current: currentDensity,
            wire: wireDensityButton
        },
        // P2-06-3. Exposed so a screen that just marked a notification read can
        // refresh the header count without a reload.
        updateNotificationBadge: updateNotificationBadge,
        // F70. Applies an AJAX validation-failure payload to the form: marks each
        // field, writes an inline message, OPENS the collapsed section a failed field
        // lives in, and builds the summary. The editor posts by AJAX, so none of the
        // server-rendered error story could ever fire without this.
        applyFormErrors: applyFormErrors,
        clearFormErrors: clearFormErrors,
        // P2-06-6. ColReorder is pointer-only; this is the keyboard path, built on
        // its own `colReorder.move()` so there is one mover and two ways to reach it.
        wireColumnReorderKeys: wireColumnReorderKeys
    };
})();
