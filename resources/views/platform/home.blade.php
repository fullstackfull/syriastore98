{{--
    M5 — THE OFFICIAL SITE'S FRONT PAGE.

    Every claim on this page is one the product can support today, and the three that could drift
    are not written here at all: the theme names come from `themes/` on disk, the plan names and
    prices come from the `plans` table, and the capability copy comes from
    `App\Support\Platform\PlatformCapabilities`. What is left in this file is description, not
    specification.
--}}
@extends('platform.layout')

@section('title', __('Overview'))
@section('meta_description', __('Store Syria is a multi-store commerce platform: a themed online shop, a merchant dashboard and an admin app, in Arabic and English.'))

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ $c['home.hero.eyebrow'] }}</span>
                <h1 class="ss-ps-hero__title">{{ $c['home.hero.title'] }}</h1>
                <p class="ss-ps-lede">{{ $c['home.hero.lede'] }}</p>
                <div class="ss-ps-actions">
                    <a class="ss-ps-btn ss-ps-btn--primary ss-ps-btn--lg" href="{{ route('platform.pricing') }}">{{ __('See plans and prices') }}</a>
                    <a class="ss-ps-btn ss-ps-btn--quiet ss-ps-btn--lg" href="{{ route('platform.onboarding') }}">{{ __('How it works') }}</a>
                </div>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">
            <div class="ss-ps-section__head">
                <h2 class="ss-ps-h2">{{ __('What you get') }}</h2>
                <p class="ss-ps-body">{{ __('One account, three surfaces. They share the same products, the same orders and the same customers, so nothing has to be kept in step by hand.') }}</p>
            </div>

            <div class="ss-ps-grid">
                <article class="ss-ps-card">
                    <span class="ss-ps-card__icon" aria-hidden="true">1</span>
                    <h3 class="ss-ps-h3">{{ __('A shop your customers visit') }}</h3>
                    <p class="ss-ps-body">{{ __('A public storefront at its own address, with a theme you choose and can edit: sections, text, images and colours, with a draft you can preview before anything goes live.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <span class="ss-ps-card__icon" aria-hidden="true">2</span>
                    <h3 class="ss-ps-h3">{{ __('A dashboard you work in') }}</h3>
                    <p class="ss-ps-body">{{ __('Products and stock, orders and their status, customers, coupons, shipping and tax, reports, and staff accounts with the permissions you decide.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <span class="ss-ps-card__icon" aria-hidden="true">3</span>
                    <h3 class="ss-ps-h3">{{ __('An admin app on your phone') }}</h3>
                    <p class="ss-ps-body">{{ __('The Store Syria admin app reads the same account: check orders, update a product or watch the day go by without opening a computer.') }}</p>
                </article>
            </div>
        </div>
    </section>

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-section__head">
                <h2 class="ss-ps-h2">{{ __('Built for merchants who work in Arabic') }}</h2>
                <p class="ss-ps-body">{{ __('Arabic is not an afterthought here. Both the shop and the dashboard are laid out right to left when the language is Arabic, and every screen is checked in both directions before it ships.') }}</p>
            </div>

            <div class="ss-ps-grid ss-ps-grid--2">
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('Arabic and English, side by side') }}</h3>
                    <p class="ss-ps-body">{{ __('A visitor reads the shop in the language they choose. You work in the language you prefer. The two are the same system, not two installations.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('More than one shop from one account') }}</h3>
                    <p class="ss-ps-body">{{ __('A plan sets how many stores an account may open. Each keeps its own products, orders, customers and theme, and you move between them from the same sign-in.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('Staff, with the access you grant') }}</h3>
                    <p class="ss-ps-body">{{ __('Create roles, decide what each one may see and change, and give a colleague their own sign-in instead of sharing yours.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('Your shop stays yours') }}</h3>
                    <p class="ss-ps-body">{{ __('Each store is separated from every other store on the platform: its catalogue, its orders and its customers are reachable only from that store.') }}</p>
                </article>
            </div>
        </div>
    </section>

    @if (count($themes) > 0)
        <section class="ss-ps-section">
            <div class="ss-ps-wrap">
                <div class="ss-ps-section__head">
                    <h2 class="ss-ps-h2">{{ __('Storefront themes') }} <span class="ss-ps-tag">{{ count($themes) }}</span></h2>
                    <p class="ss-ps-body">{{ __('Pick a theme, then make it yours in the editor. Which themes an account may use is set by its plan.') }}</p>
                </div>
                <ul class="ss-ps-tags">
                    @foreach ($themes as $theme)
                        <li class="ss-ps-tag">{{ $theme }}</li>
                    @endforeach
                </ul>
                <p class="ss-ps-after">
                    <a class="ss-ps-btn ss-ps-btn--quiet" href="{{ route('platform.features') }}">{{ __('See what the platform does') }}</a>
                </p>
            </div>
        </section>
    @endif

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-section__head">
                <h2 class="ss-ps-h2">{{ __('Plans') }}</h2>
                <p class="ss-ps-body">{{ __('Every plan is listed with the limits it actually carries. Nothing on this site is an estimate.') }}</p>
            </div>

            @if ($plans->isEmpty())
                {{-- NOT A PLACEHOLDER PRICE. An installation with no published plan says so. --}}
                <p class="ss-ps-note">{{ __('No subscription plans have been published yet. Plans and their prices will appear here as soon as they are available.') }}</p>
            @else
                <div class="ss-ps-plans">
                    @foreach ($plans->take(3) as $plan)
                        <article class="ss-ps-plan">
                            <h3 class="ss-ps-plan__name">{{ $plan->name }}</h3>
                            <p class="ss-ps-plan__price">
                                <span class="ss-ps-plan__amount">{{ default_currency_format_with_sym((float) $plan->price) }}</span>
                                @if (! empty($plan->duration))
                                    <span class="ss-ps-plan__period">{{ __(\App\Models\Plan::$arrDuration[$plan->duration] ?? $plan->duration) }}</span>
                                @endif
                            </p>
                            @if (! empty($plan->description))
                                <p class="ss-ps-body">{{ $plan->description }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
                <p class="ss-ps-after">
                    <a class="ss-ps-btn ss-ps-btn--primary" href="{{ route('platform.pricing') }}">{{ __('Compare every plan') }}</a>
                </p>
            @endif
        </div>
    </section>

@endsection
