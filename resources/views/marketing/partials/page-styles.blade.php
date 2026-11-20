{{--
    Shared chrome for the two standalone marketing pages (unsubscribe, communication preferences).

    IT IS A STYLE PARTIAL, NOT A LAYOUT, on purpose. Both pages render their own <html> because there are
    four copies of the storefront layout (one per theme plus the fallback) and these must not depend on
    which one a store resolves, because the reader may arrive logged out with no theme assets, and because
    the `<x-*-layout>` slot shape renders NOTHING under this project's @yield layouts (pinned by
    tests/Unit/Ui/SlotLayoutViewsRenderTest). Sharing only the stylesheet keeps them identical to look at
    without reintroducing a layout.

    EVERY COLOUR IS A TOKEN WITH A DARK-MODE VALUE. Phase 02's verification found option names rendering
    at 1.00:1 white-on-white in dark mode, so the dark values here are declared rather than inherited, and
    text/background pairs are chosen to stay legible in both.
--}}
<style>
    :root {
        --ss-bg: #f5f6f8;
        --ss-surface: #ffffff;
        --ss-text: #1b1f24;
        --ss-muted: #4a5159;
        --ss-border: #d7dbe0;
        --ss-accent: #0b5fff;
        --ss-accent-text: #ffffff;
        --ss-ok-bg: #e7f4ec;
        --ss-ok-text: #15603a;
        --ss-block-bg: #fdecec;
        --ss-block-text: #8a1c1c;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --ss-bg: #14171a;
            --ss-surface: #1e2227;
            --ss-text: #f2f4f6;
            --ss-muted: #b9c0c7;
            --ss-border: #343a41;
            --ss-accent: #6aa1ff;
            --ss-accent-text: #10151b;
            --ss-ok-bg: #16301f;
            --ss-ok-text: #9fe0b7;
            --ss-block-bg: #3a1c1c;
            --ss-block-text: #ff9b9b;
        }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        background: var(--ss-bg);
        color: var(--ss-text);
        font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
        line-height: 1.6;
        display: flex;
        min-height: 100vh;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
    }
    .ss-card {
        background: var(--ss-surface);
        border: 1px solid var(--ss-border);
        border-radius: 12px;
        max-width: 34rem;
        width: 100%;
        padding: 28px 24px;
    }
    .ss-store { color: var(--ss-muted); font-size: 0.875rem; margin: 0 0 8px; }
    h1 { font-size: 1.375rem; margin: 0 0 12px; }
    p { margin: 0 0 12px; }
    .ss-muted { color: var(--ss-muted); font-size: 0.9375rem; }
    .ss-contact {
        display: inline-block;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        background: var(--ss-bg);
        border: 1px solid var(--ss-border);
        border-radius: 6px;
        padding: 2px 8px;
        direction: ltr;
        unicode-bidi: isolate;
    }
    button {
        font: inherit;
        cursor: pointer;
        background: var(--ss-accent);
        color: var(--ss-accent-text);
        border: 1px solid transparent;
        border-radius: 8px;
        padding: 12px 20px;
        margin-top: 8px;
    }
    button:hover { filter: brightness(0.95); }
    button:focus-visible { outline: 3px solid var(--ss-accent); outline-offset: 2px; }
    .ss-note { border-top: 1px solid var(--ss-border); margin-top: 20px; padding-top: 14px; }
    .ss-row {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        border: 1px solid var(--ss-border);
        border-radius: 8px;
        padding: 12px 14px;
        margin-bottom: 10px;
    }
    .ss-row input[type="checkbox"] { width: 1.15rem; height: 1.15rem; margin-top: 0.3rem; flex: none; }
    .ss-row label { font-weight: 600; }
    .ss-badge {
        display: inline-block;
        font-size: 0.8125rem;
        border-radius: 999px;
        padding: 1px 10px;
        margin-inline-start: 6px;
    }
    .ss-badge--ok { background: var(--ss-ok-bg); color: var(--ss-ok-text); }
    .ss-badge--blocked { background: var(--ss-block-bg); color: var(--ss-block-text); }
</style>
