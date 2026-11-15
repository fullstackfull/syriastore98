{{--
    M5 — HOW A MERCHANT STARTS.

    The steps describe the route that exists in the product today: register, choose a plan, create
    a store, pick and edit a theme, add products, take the shop live. No step promises an
    assisted-migration service, a data-import concierge or a launch review, because the platform
    offers none of those.
--}}
@extends('platform.layout')

@section('title', __('Getting started'))
@section('meta_description', __('What happens between signing up for Store Syria and taking a shop live.'))

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ __('Getting started') }}</span>
                <h1 class="ss-ps-hero__title">{{ __('From signing up to a shop that is open') }}</h1>
                <p class="ss-ps-lede">{{ __('There are six steps, and none of them needs a developer.') }}</p>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">
            <ol class="ss-ps-steps">
                <li class="ss-ps-step">
                    <div>
                        <h2 class="ss-ps-h3">{{ __('Create your account') }}</h2>
                        <p class="ss-ps-body">{{ __('Register as a merchant and confirm your email address. The account is yours; colleagues get their own later.') }}</p>
                    </div>
                </li>
                <li class="ss-ps-step">
                    <div>
                        <h2 class="ss-ps-h3">{{ __('Choose a plan') }}</h2>
                        <p class="ss-ps-body">{{ __('The plan decides how many stores and products you may have, how much storage you get and which optional parts of the platform you can switch on.') }}</p>
                    </div>
                </li>
                <li class="ss-ps-step">
                    <div>
                        <h2 class="ss-ps-h3">{{ __('Create your first store') }}</h2>
                        <p class="ss-ps-body">{{ __('Name it, give it a web address and set its language and currency. The store is where everything else lives.') }}</p>
                    </div>
                </li>
                <li class="ss-ps-step">
                    <div>
                        <h2 class="ss-ps-h3">{{ __('Pick a theme and make it yours') }}</h2>
                        <p class="ss-ps-body">{{ __('Choose a storefront theme, then change its sections, text, images and colours in the editor. Your changes are a draft until you publish them, and you can preview the draft privately first.') }}</p>
                    </div>
                </li>
                <li class="ss-ps-step">
                    <div>
                        <h2 class="ss-ps-h3">{{ __('Add your products') }}</h2>
                        <p class="ss-ps-body">{{ __('Enter products one at a time, or bring a catalogue in from a file. Set categories, variants, prices and stock.') }}</p>
                    </div>
                </li>
                <li class="ss-ps-step">
                    <div>
                        <h2 class="ss-ps-h3">{{ __('Open the shop') }}</h2>
                        <p class="ss-ps-body">{{ __('Set up payment and delivery, publish the theme and give out the address. Orders arrive in the dashboard and in the admin app.') }}</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-section__head">
                <h2 class="ss-ps-h2">{{ __('What you need before you start') }}</h2>
            </div>
            <div class="ss-ps-grid">
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('An email address you control') }}</h3>
                    <p class="ss-ps-body">{{ __('It confirms the account and receives everything the platform sends you.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('Your products, with pictures') }}</h3>
                    <p class="ss-ps-body">{{ __('Names, prices and photographs. A spreadsheet is enough to begin; it does not have to be complete.') }}</p>
                </article>
                <article class="ss-ps-card">
                    <h3 class="ss-ps-h3">{{ __('How you want to be paid and to deliver') }}</h3>
                    <p class="ss-ps-body">{{ __('Which payment methods you accept, and where and how you send orders.') }}</p>
                </article>
            </div>
        </div>
    </section>

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            @if ($plans->isEmpty())
                <p class="ss-ps-note">{{ __('No subscription plans have been published yet. Plans and their prices will appear here as soon as they are available.') }}</p>
            @else
                <div class="ss-ps-actions">
                    <a class="ss-ps-btn ss-ps-btn--primary ss-ps-btn--lg" href="{{ route('platform.pricing') }}">{{ __('See plans and prices') }}</a>
                    <a class="ss-ps-btn ss-ps-btn--quiet ss-ps-btn--lg" href="{{ route('platform.contact') }}">{{ __('Ask a question') }}</a>
                </div>
            @endif
        </div>
    </section>

@endsection
