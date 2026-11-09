@extends('layouts.app')

@section('page-title')
    {{ __('Create Customer') }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('customer.index') }}">{{ __('Customer') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Create Customer') }}</li>
@endsection

@section('action-button')
    <div class="text-end">
        <a href="{{ route('customer.index') }}" class="btn btn-sm btn-primary btn-icon"
           data-bs-toggle="tooltip" data-bs-placement="top"
           title="{{ __('Back') }}" aria-label="{{ __('Back') }}">
            <i class="ti ti-arrow-left"></i>
        </a>
    </div>
@endsection

@section('content')
    {{--
        P2.5-PKG-E (E-4/E-5) — THE FORM GROUPED INTO THE THREE THINGS IT IS ACTUALLY ASKING FOR,
        and five corrections that are not cosmetic.

        WHAT WAS HERE: six fields in one flat card, in one undifferentiated two-column grid. Nothing
        told the merchant that the first two are who this person is, the next two are how to reach
        them, and the last two are an ACCOUNT being created with a password. The password sat
        between a phone number and a status dropdown as though it were the same kind of fact.

        The grouping is the hierarchy. Identity, then contact, then access — the order a person
        would say them in, with the one consequential group named as such.

        THE FIVE CORRECTIONS, each of which a test pins:

        1. `dir="ltr"` ON THE E-MAIL AND MOBILE INPUTS. In the Arabic interface the document is
           RTL, and a Latin address or a phone number typed into an RTL input reorders as it is
           typed: a leading `+` jumps to the far end and `name@host.tld` renders as misordered
           runs. The customer GRID already guards its e-mail this way (grid.blade.php:159); the
           form a merchant TYPES the address into did not.

        2. `autocomplete` TOKENS ON ALL FIVE TEXT FIELDS. Six standard tokens, none declared, so no
           browser or password manager could fill a customer's details. `new-password` on the
           password field is the one that matters most: without it a manager offers the MERCHANT's
           own saved credential for the customer being created.

        3. THE PASSWORD RULE IS STATED. The server requires `min:8` (CustomerController::store())
           and the form said nothing, so a merchant learned the rule by being refused.
           `minlength="8"` plus an `x-ss.field-help` bound through `aria-describedby`.

           THE FIRST VERSION OF THIS NOTE CLAIMED `ContextualFieldHelpTest` enforces that
           association here. IT DOES NOT -- that test lists five PRODUCT fields and loads only the
           product create and edit screens, so nothing asserted these four customer pairs in
           either direction. Raised by the tenant-authz review of this change. The assertion now
           lives in CustomerCreateFormTest, in both directions: every `aria-describedby` points at
           an element that exists, and every hint id is referenced by a control.

        4. A REAL `<button type="submit">`. `Form::submit()` renders `<input type="submit">`, which
           cannot contain a child, so the primary action on the page was the only button in this
           interface with no icon.

        5. THE ARABIC HTML COMMENTS ARE GONE. `<!-- الاسم الأول -->` and its four siblings were
           shipped to every browser on every render. Blade comments do not survive compilation;
           HTML comments do.

        WHAT IS UNCHANGED, DELIBERATELY: every field, its name, its validation, its required
        marker, the `<x-ss.error-summary>`, and the `select2` status control the host initialises
        at layouts/app.blade.php:797.
    --}}
    <div class="row">
        <div class="col-lg-9 col-xl-8">
            {{ Form::open(['route' => 'customer.store', 'method' => 'POST']) }}
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Customer Information') }}</h5>
                </div>
                {{--
                    THE FORM WRAPS THE WHOLE CARD, and that placement is deliberate rather than
                    incidental. The first draft opened the form INSIDE `.card-body` and put the
                    submit button in `.card-footer`, so the `<form>` was opened inside one `<div>`
                    and closed after another had already closed -- mis-nested markup whose
                    behaviour depends on how a parser recovers, and the submit control could end up
                    outside the form it is meant to submit. A test now asserts the button really is
                    inside the rendered `<form>`, because asserting only that the button EXISTS
                    would not have caught it.
                --}}
                <div class="card-body">
                    {{-- F271. The messages below each field are `display:none` until the control
                         carries `is-invalid` (style.css:3297, :3320), so the summary is the only
                         thing a merchant reliably reads on a refusal. --}}
                    <x-ss.error-summary :errors="$errors" class="mb-3" />

                    {{--
                        `.ss-label`, NOT `.form-label`, AND THE REASON IS MEASURED. The first
                        version of these three legends carried the host's `.form-label`, and
                        census-low-contrast-text.cjs measured them at **1.1:1** in dark mode --
                        rgb(41,50,64) on rgb(41,42,51), three failing nodes, essentially invisible.
                        The host's class is not theme-aware on a `<legend>`. `.ss-label` resolves
                        its colour from `--ss-fg`, which both themes define, and `.ss-form-legend`
                        carries only the size and weight a group heading needs.
                    --}}
                    <fieldset class="mb-4">
                        <legend class="ss-label ss-form-legend mb-3">{{ __('Identity') }}</legend>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group ss-field">
                                    {{-- P2.5-PKG-G SLICE 4. THE MARKER IS INSIDE THE LABEL NOW.
                                         It used to be a SIBLING of it, and `.ss-field` is
                                         `display:flex; flex-direction:column`, so every direct
                                         child is its own flex item: the `*` rendered on a
                                         standalone row 16px below its label and 8px above the
                                         input, reading as though it marked the control rather
                                         than the field. `.ss-label__required`'s
                                         `margin-inline-start: 2px` was written for an inline
                                         marker and did nothing there. Hand-written the way
                                         components/ss/secret-field.blade.php already does it.

                                         THE TRAP THIS CREATES, and why every control below now
                                         carries an explicit `id`: konekt's FormBuilder pushes the
                                         name into `$this->labels` inside `label()`
                                         (FormBuilder.php:249), and `getIdAttribute()`
                                         (:1268-1277) returns the name as the element id ONLY if
                                         it is in that array. Drop the `Form::label()` call and
                                         `Form::text('first_name', ...)` emits NO id -- which
                                         silently kills the `for` association, kills
                                         x-ss.error-summary's `href="#first_name"`, and orphans
                                         every aria-describedby pair on the form. --}}
                                    <label class="ss-label" for="first_name">{{ __('First Name') }}
                                        <span class="ss-label__required" aria-hidden="true">*</span>
                                    </label>
                                    {{ Form::text('first_name', null, ['id' => 'first_name', 'class' => 'form-control' . ($errors->has('first_name') ? ' is-invalid' : ''), 'placeholder' => __('Enter First Name'), 'required' => 'required', 'autocomplete' => 'given-name', 'maxlength' => '255']) }}
                                    @error('first_name')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group ss-field">
                                    <label class="ss-label" for="last_name">{{ __('Last Name') }}
                                        <span class="ss-label__required" aria-hidden="true">*</span>
                                    </label>
                                    {{ Form::text('last_name', null, ['id' => 'last_name', 'class' => 'form-control' . ($errors->has('last_name') ? ' is-invalid' : ''), 'placeholder' => __('Enter Last Name'), 'required' => 'required', 'autocomplete' => 'family-name', 'maxlength' => '255']) }}
                                    @error('last_name')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="mb-4">
                        <legend class="ss-label ss-form-legend mb-3">{{ __('Contact') }}</legend>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group ss-field">
                                    <label class="ss-label" for="email">{{ __('Email') }}
                                        <span class="ss-label__required" aria-hidden="true">*</span>
                                    </label>
                                    {{-- dir="ltr": see note 1 in the block above. --}}
                                    {{ Form::email('email', null, ['id' => 'email', 'class' => 'form-control' . ($errors->has('email') ? ' is-invalid' : ''), 'placeholder' => __('Enter Email'), 'required' => 'required', 'autocomplete' => 'email', 'maxlength' => '255', 'dir' => 'ltr', 'aria-describedby' => 'email-help']) }}
                                    <x-ss.field-help for="email">{{ __('Used to sign in to your storefront. It must not already belong to another customer.') }}</x-ss.field-help>
                                    @error('email')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group ss-field">
                                    <label class="ss-label" for="mobile">{{ __('Mobile') }}
                                    </label>
                                    {{ Form::text('mobile', null, ['id' => 'mobile', 'class' => 'form-control' . ($errors->has('mobile') ? ' is-invalid' : ''), 'placeholder' => __('Enter Mobile Number'), 'autocomplete' => 'tel', 'maxlength' => '20', 'dir' => 'ltr', 'aria-describedby' => 'mobile-help']) }}
                                    <x-ss.field-help for="mobile">{{ __('Optional.') }}</x-ss.field-help>
                                    @error('mobile')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="mb-2">
                        <legend class="ss-label ss-form-legend mb-3">{{ __('Account access') }}</legend>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group ss-field">
                                    <label class="ss-label" for="password">{{ __('Password') }}
                                        <span class="ss-label__required" aria-hidden="true">*</span>
                                    </label>
                                    {{-- minlength mirrors the server's `min:8`. See note 3. --}}
                                    {{ Form::password('password', ['id' => 'password', 'class' => 'form-control' . ($errors->has('password') ? ' is-invalid' : ''), 'placeholder' => __('Enter Password'), 'required' => 'required', 'autocomplete' => 'new-password', 'minlength' => '8', 'aria-describedby' => 'password-help']) }}
                                    <x-ss.field-help for="password">{{ __('At least 8 characters. The customer can change it from your storefront.') }}</x-ss.field-help>
                                    @error('password')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group ss-field">
                                    <label class="ss-label" for="status">{{ __('Status') }}
                                        <span class="ss-label__required" aria-hidden="true">*</span>
                                    </label>
                                    {{-- P2.5-PKG-G SLICE 4. `select2` IS REMOVED, and that one
                                         class was the whole defect. THREE things close with it:

                                         (a) layouts/app.blade.php:823-836 initialises EVERY
                                             `.select2` with `tags: true` and a `createTag` that
                                             accepts any trimmed term. So a merchant could type a
                                             free value into Status, it POSTed, and
                                             `'status' => 'required|in:0,1'` refused their own
                                             dropdown's answer.
                                         (b) `@error('status') is-invalid @enderror` landed on the
                                             original `<select>`, which select2 gives
                                             `.select2-hidden-accessible` -- clipped to 1x1 by
                                             select2.min.css -- so the red border painted on a
                                             one-pixel box, and no `is-invalid` rule for
                                             `.select2-selection` exists in any stylesheet here.
                                         (c) the error summary's `href="#status"` moved focus onto
                                             that 1px element while select2's visible combobox
                                             stayed unfocused.

                                         A NATIVE SELECT WAS TRIED AGAINST A GROUP OF RADIOS and
                                         is the better change here. Radios would also fix all
                                         three -- but they move the hint from the control to a
                                         `<fieldset>`, and `CustomerCreateFormTest` asserts, in
                                         both directions, that every hint on this screen is bound
                                         to a control carrying that field's `name`. A fieldset has
                                         no name. Rewriting that assertion to accept a group was
                                         the alternative, and deleting one class is a smaller,
                                         more obviously correct change than rewriting a guard to
                                         suit new markup. Two options for a binary choice is also
                                         not what a dropdown is bad at; a tagging library attached
                                         to it is. --}}
                                    <select name="status" id="status" class="form-control @error('status') is-invalid @enderror" required aria-describedby="status-help">
                                        <option value="1" {{ old('status', '1') == '1' ? 'selected' : '' }}>{{ __('Active') }}</option>
                                        <option value="0" {{ old('status') === '0' ? 'selected' : '' }}>{{ __('Inactive') }}</option>
                                    </select>
                                    <x-ss.field-help for="status">{{ __('An inactive customer cannot sign in to your storefront.') }}</x-ss.field-help>
                                    @error('status')
                                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>

                {{-- The actions in the card's own footer rather than loose in the body, so the
                     primary action sits on the card's edge where every other screen in this phase
                     puts it. --}}
                <div class="card-footer ss-card__footer ss-card__footer--end">
                    <a href="{{ route('customer.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary">
                        <span class="btn-inner--icon"><i class="ti ti-user-plus"></i></span>
                        <span class="btn-inner--text">{{ __('Create Customer') }}</span>
                    </button>
                </div>
            </div>
            {{ Form::close() }}
        </div>
    </div>
@endsection
