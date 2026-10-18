{{--
    THE ONE-TIME TOKEN REVEAL.

    IntegrationController flashes the freshly minted token to the session on issue/rotate
    (->with('lynomia_commerce_credential', ...)). Flash data lives for exactly the next request,
    so this banner renders once, on the redirect back from issuing, and is gone on any later load
    -- which is the "shown once, never retrievable again" rule the token's whole design rests on
    (the token is derived and never stored, so there is nothing to show it from a second time).

    SECURITY. The token is placed only in a readonly input on this operator-only page. It is never
    written to a URL, to localStorage, to analytics, or to the console; the copy button reads it
    straight from the input. Blade escapes the value, and a commerce token is [A-Za-z0-9_] so it
    cannot break out of the attribute regardless.
--}}
@if (session()->has('lynomia_commerce_credential'))
    <div class="alert alert-warning" role="alert" id="lyn-credential-reveal">
        <h6 class="mb-1">{{ __('Copy your commerce token now') }}</h6>
        <p class="small mb-2">
            {{ __('This token is shown once and cannot be retrieved again. If you lose it, issue a new one — which will stop this one from working.') }}
        </p>
        <div class="input-group">
            <input type="text" readonly class="form-control" id="lyn-credential-value"
                   value="{{ session('lynomia_commerce_credential') }}"
                   onfocus="this.select();" autocomplete="off" spellcheck="false" aria-label="{{ __('Commerce token') }}">
            <button type="button" class="btn btn-outline-secondary" id="lyn-credential-copy">{{ __('Copy Token') }}</button>
        </div>
        <div class="small text-success mt-1 d-none" id="lyn-credential-copied">{{ __('Copied.') }}</div>
    </div>
    @push('scripts')
    <script>
    (function () {
        var button = document.getElementById('lyn-credential-copy');
        var field = document.getElementById('lyn-credential-value');
        var copied = document.getElementById('lyn-credential-copied');
        if (!button || !field) { return; }

        button.addEventListener('click', function () {
            // Read straight from the field; the token is never held in a variable that outlives
            // this handler, and it is never logged.
            field.focus();
            field.select();

            var done = function () { if (copied) { copied.classList.remove('d-none'); } };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () {
                    try { document.execCommand('copy'); done(); } catch (e) { /* leave it selected to copy by hand */ }
                });
            } else {
                try { document.execCommand('copy'); done(); } catch (e) { /* leave it selected */ }
            }
        });
    })();
    </script>
    @endpush
@endif
