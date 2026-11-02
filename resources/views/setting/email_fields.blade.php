{{--
    The mail-driver fields, on the design system.
    =============================================================================================

    THIRTEEN NEAR-IDENTICAL COPIES COLLAPSED INTO ONE. This fragment was 200 lines of
    `@if/@elseif` over `$email_setting`, repeating the same `mail_host` field thirteen times and
    the same `mail_username` field thirteen more, each differing only by a preset value or a
    label. That duplication was not cosmetic — it is where all three defects below came from, and
    it is why they could sit in different copies of the same field without anyone noticing.

    1. THE READONLY FIELDS WERE UNREADABLE IN DARK MODE, and this is measured, not inferred. The
       fragment carried its own `<script>` doing

           $('input[readonly="readonly"]').css('background-color', '#e9ecef');

       — a hard-coded light grey, applied DOCUMENT-WIDE rather than to this fragment, so it also
       recoloured readonly inputs on other settings panels. Against the dark theme's
       `--ss-fg-muted` (#a7b2b1) that background gives **1.84:1**, where AA needs 4.5:1. The
       design system already styles `.ss-input[readonly]` from `--ss-surface-sunken`, which
       measures **6.79:1** in the same theme, so the script is deleted rather than ported: its
       whole job is now done correctly by a token.

    2. THE SMTP.COM FIELD ASKED FOR A MAILGUN USERNAME. Its label and placeholder both read
       "Your Mailgun SMTP username" — a copy-paste from the branch above it. It now uses the
       generic `Mail Username`, which is the honest label and is already translated in all
       sixteen locale files; the provider is named by the driver select directly above. Minting a
       new "Your SMTP.com username" key would have been correct English and an untranslated
       string in fifteen languages.

    3. ONE LABEL POINTED AT NOTHING: `<label for="">` on Mail Encryption in the `custom` branch,
       so that field had no accessible name while the identical field in the `smtp` branch did.

    THE OUTLOOK LABEL KEY KEEPS ITS LEADING SPACE, deliberately. `' Your Outlook/Office 365 email
    address'` is the key that is actually present in `resources/lang/*.json`; the trimmed spelling
    is NOT. Tidying the whitespace would have silently dropped the Arabic translation and shown
    English to an Arabic merchant — the sort of regression that no test and no screenshot of the
    English page would catch.

    WHAT IS DELIBERATELY UNCHANGED: every field `name` (`mail_driver`, `mail_host`, `mail_port`,
    `mail_username`, `mail_password`, `mail_encryption`, `mail_from_address`, `mail_from_name`),
    every `id`, which driver keys get a fixed host, which get an editable one, and the readonly
    `587`/`tls` defaults. `SettingsPanelContractTest` pins the name set.
--}}
@php
    /*
     * The per-driver presets. `host` null means the merchant types it; a string is the fixed
     * value, rendered readonly exactly as before. `username` is the label and placeholder key for
     * the username box. `editable` lists the drivers whose port and encryption the merchant sets;
     * every other driver gets the readonly 587/tls pair, which is what the old `@else` branches
     * did.
     *
     * Keys are `EmailTemplate::$email_settings`, all thirteen of them. A driver that is not in
     * this table gets the editable shape -- the old chain rendered NO host field at all for an
     * unknown driver, which posted an empty `mail_host` and blanked the stored one.
     */
    $presets = [
        'custom'    => ['host' => null,                                   'username' => 'Mail Username'],
        'smtp'      => ['host' => null,                                   'username' => 'Mail Username'],
        'gmail'     => ['host' => 'smtp.gmail.com',                       'username' => 'Your Gmail email address'],
        'outlook'   => ['host' => 'smtp.office365.com',                   'username' => ' Your Outlook/Office 365 email address'],
        'yahoo'     => ['host' => 'smtp.mail.yahoo.com',                  'username' => 'Your Yahoo email address'],
        'sendgrid'  => ['host' => 'smtp.sendgrid.net',                    'username' => 'Your SendGrid username or API key'],
        'amazon'    => ['host' => 'email-smtp.us-east-1.amazonaws.com',   'username' => 'Your AWS IAM username or access key ID'],
        'mailgun'   => ['host' => 'smtp.mailgun.org',                     'username' => 'Your Mailgun SMTP username'],
        'smtp.com'  => ['host' => 'smtp.smtp.com',                        'username' => 'Mail Username'],
        'zohomail'  => ['host' => 'smtp.zoho.com',                        'username' => 'Your Zoho Mail email address'],
        'mandrill'  => ['host' => 'smtp.mandrillapp.com',                 'username' => 'Your Mandrill API key'],
        'mailtrap'  => ['host' => 'smtp.mailtrap.io',                     'username' => 'Your Mailtrap username'],
        'sparkpost' => ['host' => 'smtp.sparkpostmail.com',               'username' => 'Your SparkPost SMTP username'],
    ];

    $preset       = $presets[$email_setting] ?? ['host' => null, 'username' => 'Mail Username'];
    $editable     = in_array($email_setting, ['custom', 'smtp'], true) || ! isset($presets[$email_setting]);
    $usernameText = __($preset['username']);
@endphp

<div class="row">
    <div class="col-md-4">
        <div class="ss-field">
            <label for="mail_driver" class="ss-label">{{ __('Mail Driver') }}</label>
            {{ Form::text('mail_driver', $setting['MAIL_DRIVER'] ?? null, ['class' => 'ss-input', 'placeholder' => __('Enter Mail Driver'), 'id' => 'mail_driver']) }}
        </div>
    </div>

    <div class="col-md-4">
        <div class="ss-field">
            <label for="mail_host" class="ss-label">{{ __('Mail Host') }}</label>
            @if ($preset['host'] === null)
                {{ Form::text('mail_host', $setting['MAIL_HOST'] ?? null, ['class' => 'ss-input', 'placeholder' => __('Enter Mail Host'), 'id' => 'mail_host']) }}
            @else
                {{-- Fixed by the provider. `readonly`, not `disabled`: a disabled input posts
                     nothing, which would blank the stored host on save. --}}
                <input type="text" class="ss-input" id="mail_host" name="mail_host"
                       value="{{ $preset['host'] }}" readonly="readonly">
            @endif
        </div>
    </div>

    <div class="col-md-4">
        <div class="ss-field">
            <label for="mail_port" class="ss-label">{{ __('Mail Port') }}</label>
            @if ($editable)
                {{ Form::text('mail_port', $setting['MAIL_PORT'] ?? null, ['class' => 'ss-input', 'placeholder' => __('Enter Mail Port'), 'id' => 'mail_port']) }}
            @else
                <input type="text" class="ss-input" id="mail_port" name="mail_port"
                       value="587" readonly="readonly">
            @endif
        </div>
    </div>

    <div class="col-md-4">
        <div class="ss-field">
            <label for="mail_username" class="ss-label">{{ $usernameText }}</label>
            {{ Form::text('mail_username', $setting['MAIL_USERNAME'] ?? null, ['class' => 'ss-input', 'placeholder' => $usernameText, 'id' => 'mail_username']) }}
        </div>
    </div>

    <div class="col-md-4">
        {{-- F117: the stored credential never reaches the page; the handler keeps it when this
             box posts empty. Note the NAME CHANGE: the field posts as `mail_password` and is
             stored as `MAIL_PASSWORD`. --}}
        <x-ss.secret-field
            name="mail_password"
            :label="__('Mail Password')"
            :stored="$setting['MAIL_PASSWORD'] ?? null" />
    </div>

    <div class="col-md-4">
        <div class="ss-field">
            {{-- The `custom` copy of this label was `for=""`. --}}
            <label for="mail_encryption" class="ss-label">{{ __('Mail Encryption') }}</label>
            @if ($editable)
                {{ Form::text('mail_encryption', $setting['MAIL_ENCRYPTION'] ?? null, ['class' => 'ss-input', 'placeholder' => __('Enter Mail Encryption'), 'id' => 'mail_encryption']) }}
            @else
                <input type="text" class="ss-input" id="mail_encryption" name="mail_encryption"
                       value="tls" readonly="readonly">
            @endif
        </div>
    </div>

    <div class="col-md-4">
        <div class="ss-field">
            <label for="mail_from_address" class="ss-label">{{ __('Mail From Address') }}</label>
            {{ Form::text('mail_from_address', $setting['MAIL_FROM_ADDRESS'] ?? null, ['class' => 'ss-input', 'placeholder' => __('Enter Mail From Address'), 'id' => 'mail_from_address']) }}
        </div>
    </div>

    <div class="col-md-4">
        <div class="ss-field">
            <label for="mail_from_name" class="ss-label">{{ __('Mail From Name') }}</label>
            {{ Form::text('mail_from_name', $setting['MAIL_FROM_NAME'] ?? null, ['class' => 'ss-input', 'placeholder' => __('Enter Mail From Name'), 'id' => 'mail_from_name']) }}
        </div>
    </div>
</div>
