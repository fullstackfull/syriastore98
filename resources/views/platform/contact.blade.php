{{--
    M5 — CONTACT.

    WHAT THIS PAGE DELIBERATELY DOES NOT DO: invent a postal address, a telephone number or a
    support mailbox. None of those exists as configuration in this codebase — a sweep of the
    settings keys the application reads found `contactus_url` and nothing else — and a public page
    that printed a plausible-looking address would be a false statement about a real business.

    So the page publishes what the installation has actually been given. `contactus_url` is a
    super-admin setting that other views already read; where it is set, it is offered as the
    channel. Where it is not, the page says the published details are still being confirmed and
    points at the routes that do exist: the signed-in dashboard, and the FAQ.

    Tracked as an owner item in the Phase 03 ledger. It is content the owner supplies, not
    engineering that is missing.

    GAP-104 (P03-C-05) -- THE OWNER NOW HAS SOMEWHERE TO PUT THEM, which is the knock-on that row
    singles out: previously these details could only be published by a developer. Four fields --
    address, telephone, email address, opening hours -- are declared OWED in
    App\Support\Platform\PlatformContent and ship with no wording. While every one of them is
    empty this page is exactly what it was. As soon as ONE is supplied the honest-blank notice is
    replaced by the details that exist, and only those.

    NOTHING IS INVENTED HERE. There is no specimen address, no example telephone number and no
    placeholder mailbox anywhere in this file or in the registry.
--}}
@extends('platform.layout')

@section('title', __('Contact'))
@section('meta_description', __('How to reach Store Syria about a plan, an account or a problem.'))

@php
    /* Filtered for the same reason as `platform/layout.blade.php` -- `getSuperAdminAllSetting()`
       returns the raw bag where `getAdminAllSetting()` does not, and this is a public page. Only
       `contactus_url` is read, so nothing leaks today; the wrapper keeps that true. */
    try {
        $settings = withoutPageExposedCredentials(getSuperAdminAllSetting());
    } catch (\Throwable $e) {
        $settings = [];
    }

    $contactUrl = trim((string) ($settings['contactus_url'] ?? ''));

    /* Only an absolute http(s) URL is rendered as a link. A setting holding something else --
       a bare word, a `javascript:` string, a relative fragment -- is treated as unset rather than
       printed into an href, because this value is operator-supplied and ends up in a public page. */
    $contactIsLinkable = $contactUrl !== ''
        && filter_var($contactUrl, FILTER_VALIDATE_URL) !== false
        && in_array(parse_url($contactUrl, PHP_URL_SCHEME), ['http', 'https'], true);

    /* GAP-104. The published details, in the order a visitor reads them. Only the ones the owner
       has actually supplied in THIS language appear; an empty one is absent, not blank. */
    $details = array_values(array_filter([
        ['label' => __('Address'),       'value' => $c['contact.address'] ?? '',   'lines' => true],
        ['label' => __('Telephone'),     'value' => $c['contact.telephone'] ?? '', 'lines' => false],
        /* `Email` and `Details` RATHER THAN `Email address` and `Our details`: both already exist
           in BOTH language files, and a key that is already translated is worth more on a public
           page than a slightly better-worded one that is not. `Opening hours` has no existing key
           and is reported as an owner translation item rather than renamed into something less
           accurate. */
        ['label' => __('Email'),         'value' => $c['contact.email'] ?? '',     'lines' => false],
        ['label' => __('Opening hours'), 'value' => $c['contact.hours'] ?? '',     'lines' => true],
    ], static fn (array $d) => $d['value'] !== ''));
@endphp

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ $c['contact.hero.eyebrow'] }}</span>
                <h1 class="ss-ps-hero__title">{{ $c['contact.hero.title'] }}</h1>
                <p class="ss-ps-lede">{{ $c['contact.hero.lede'] }}</p>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">
            <div class="ss-ps-rows">

                @if ($contactIsLinkable)
                    <div class="ss-ps-row">
                        <h2 class="ss-ps-h3">{{ __('Send us a message') }}</h2>
                        <p class="ss-ps-body">{{ __('This is the channel to use for anything before you have an account.') }}</p>
                        <p class="ss-ps-after">
                            <a class="ss-ps-btn ss-ps-btn--primary" href="{{ $contactUrl }}" rel="noopener">{{ __('Open the contact form') }}</a>
                        </p>
                    </div>
                @endif

                {{-- THE OWNER'S DETAILS, OR THE HONEST BLANK -- never both, and never invented. The
                     notice is shown only while NOTHING has been supplied, because printed beside a
                     real telephone number it would be a false statement. --}}
                @if (count($details) > 0)
                    <div class="ss-ps-row">
                        <h2 class="ss-ps-h3">{{ __('Details') }}</h2>
                        {{-- A bare `dl`: `.ss-ps-tags` is a wrapping flex ROW of chips and would
                             put a multi-line address beside its own label. The browser's own
                             `dd { margin-inline-start }` is logical, so this reads correctly in
                             both directions without a rule of its own. --}}
                        <dl>
                            @foreach ($details as $detail)
                                <dt class="ss-ps-eyebrow">{{ $detail['label'] }}</dt>
                                {{-- `bdi` and `dir="auto"` on a VALUE the owner typed: a telephone
                                     number or an email address is a left-to-right run inside an
                                     Arabic page, and a bare span puts the leading `+` on the wrong
                                     end. `nl2br` over an ESCAPED value, never the reverse. --}}
                                <dd class="ss-ps-body">
                                    <bdi dir="auto">{!! $detail['lines'] ? nl2br(e($detail['value'])) : e($detail['value']) !!}</bdi>
                                </dd>
                            @endforeach
                        </dl>
                    </div>
                @elseif (! $contactIsLinkable)
                    <div class="ss-ps-note">
                        <h2 class="ss-ps-h3">{{ __('Our published contact details are being confirmed') }}</h2>
                        <p class="ss-ps-body">{{ __('Rather than print details that may be out of date, we are leaving this blank until they are final. The routes below work today.') }}</p>
                    </div>
                @endif

                <div class="ss-ps-row">
                    <h2 class="ss-ps-h3">{{ __('If you already have an account') }}</h2>
                    <p class="ss-ps-body">{{ __('Sign in and raise it from the dashboard. A request made there arrives with your store and your account already attached, which is faster than describing them.') }}</p>
                    <p class="ss-ps-after">
                        <a class="ss-ps-btn ss-ps-btn--quiet" href="{{ url('login') }}">{{ __('Merchant sign in') }}</a>
                    </p>
                </div>

                <div class="ss-ps-row">
                    <h2 class="ss-ps-h3">{{ __('Before you write') }}</h2>
                    <p class="ss-ps-body">{{ __('The FAQ answers the questions we are asked most often, including what a plan includes and how theme changes are published.') }}</p>
                    <p class="ss-ps-after">
                        <a class="ss-ps-btn ss-ps-btn--quiet" href="{{ route('platform.faq') }}">{{ __('Read the FAQ') }}</a>
                    </p>
                </div>

                <div class="ss-ps-row">
                    <h2 class="ss-ps-h3">{{ __('Reporting a security problem') }}</h2>
                    <p class="ss-ps-body">{{ __('If you believe you have found a security weakness, tell us before you tell anyone else, and give us enough detail to reproduce it. Please do not test against another merchant\'s store.') }}</p>
                </div>

            </div>
        </div>
    </section>

@endsection
