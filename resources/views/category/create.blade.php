{{--
    P2-05-4 — the create-category form, on the design system.

    THE DEFECT THAT MATTERED MOST HERE IS NOT STYLING. Every label on this form was
    `Form::label('', …)`, which renders `<label for="">`: an empty `for` associates the label
    with nothing, so clicking "Title" did not focus the field and a screen reader announced each
    input as unlabelled. Five fields on this one form — Title, Image, Icon, Trending, Status.
    Laravel Collective already derives the input's `id` from its name, so naming the field in the
    label is the entire fix.

    THE TWO SWITCHES WERE WORSE: the caption beside each one was a `Form::label('', …)` naming
    nothing, and their own `<label class="form-check-label">` was EMPTY -- so the checkbox had no
    accessible name from any source. The two are now ONE label, associated with the control and
    carrying the field's name, rather than a caption and an empty label side by side. That is
    also what makes the switch clickable.

    A first attempt put the name in the empty label and left the caption above it, which
    duplicated the text and -- measured in the running modal -- wrapped "Show this category in
    Trending" over two lines into the footer. The screenshot showed it; no assertion did, which
    is why the live check now measures this form's contrast and layout rather than only its
    labelling.

    AND THE PARENT SELECT HAD NO LABEL ASSOCIATION EITHER — a bare `<label class="form-label">`
    with no `for` and a `<select>` with no `id`. It keeps its `select2` class, which is a JS hook
    that replaces the element, so it does NOT take `ss-select`: that class is for plain selects
    and would style something select2 hides.

    Styling follows: `form-control` -> `ss-input`, `form-label` -> `ss-label`, the wrappers ->
    `ss-field`, the hint -> `ss-field__hint`, the footer buttons -> the design system's. Routes,
    field names, `enctype`, the upload `onchange` handlers, the hidden `0` companions of the two
    switches and the ajax-popup contract are untouched.

    ON `{!! $category['name'] !!}` IN THE OPTIONS: that stays raw, and it is now SAFE rather than
    merely unchanged. `buildCategoryTree()` builds that string from deliberate `&nbsp;` indent
    markup plus the merchant-written name, and the name was unescaped — stored XSS in this very
    select, reachable by anyone holding `Create Product Category`, rendering for every user of
    the store who opens this form. Escaped in the helper (the only fix that also reaches the two
    vendored ProductReview views), covered by `Tests\Unit\Security\CategoryTreeEscapingTest`.
--}}

{{-- P2-05-4. `data-ss-theme="light"` IS NOT A STYLE PREFERENCE, it names the surface this form
     is actually drawn on. The host's Bootstrap modal keeps a WHITE `.modal-content` even when
     the admin is in dark mode -- measured `rgb(255, 255, 255)` with `data-ss-theme="dark"` on
     <html> -- so design-system components inside it would otherwise resolve their DARK tokens
     against white. The hint text below measured 2.18:1 that way, in both English and Arabic,
     against the 4.5:1 this slice is accepted on. Scoped here rather than by making the DS follow
     the host modal globally, because the modal's colour is the host's to decide and 70 vendored
     modules share it. --}}
<div data-ss-theme="light">
{{ Form::open(['route' => 'category.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

@if (isset(auth()->user()->currentPlan) && auth()->user()->currentPlan->enable_chatgpt == 'on')
<div class="d-flex justify-content-end mb-1">
    <a href="#" class="ss-btn ss-btn--secondary ss-btn--sm me-2 ai-btn" data-size="lg" data-ss-panel-open="ai-generate" data-ss-panel-url="{{ route('generate',['category']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
        <i class="fas fa-robot" aria-hidden="true"></i> <span>{{ __('Generate with AI') }}</span>
    </a>
</div>
@endif

<div class="row">
    <div class="ss-field col-md-12">
        {!! Form::label('name', __('Title'), ['class' => 'ss-label']) !!}
        {!! Form::text('name', null, ['class' => 'ss-input']) !!}
    </div>
    <div class="ss-field col-md-12">
        <label class="ss-label" for="parent_id">{{ __('Parents Category') }}</label>
        <select name="parent_id" id="parent_id" class="form-select select2">
            <option value="">{{ __('None') }}</option>
            @foreach ($categoryTree as $category)
                <option value="{{ $category['id'] }}">{!! $category['name'] !!}</option>
            @endforeach
        </select>
        <small class="ss-field__hint d-block">{{ __('Assign a parent category to create a hierarchy.') }}
        </small>
    </div>
    <div class="ss-field col-md-6">
        {!! Form::label('upload_image', __('Image'), ['class' => 'ss-label']) !!}

        <div class="row">
            <div class="col-md-12">
            <label for="upload_image">
                <div class="image-upload bg-primary pointer w-100 logo_update"> <i
                        class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                </div>
                <input type="file" class="form-control file d-none"
                    name="image" id="upload_image"
                    data-filename="logo_update"
                    onchange="document.getElementById('categoryImage').src = window.URL.createObjectURL(this.files[0])">
            </label>
            </div>
            <div class="logo-content mt-3 col-md-12">
                    <img src="#"
                        class="big-logo invoice_logo img_setting" id="categoryImage" width="200px">
            </div>
        </div>

    </div>
    <div class="ss-field col-md-6">
        {!! Form::label('upload_icon_image', __('Icon'), ['class' => 'ss-label']) !!}
        <div class="row">
            <div class="col-md-12">
            <label for="upload_icon_image">
                <div class="image-upload bg-primary pointer w-100 logo_update"> <i
                        class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                </div>
                <input type="file" class="form-control file d-none"
                    name="icon_image" id="upload_icon_image"
                    data-filename="logo_update"
                    onchange="document.getElementById('categoryIcon').src = window.URL.createObjectURL(this.files[0])">
            </label>
            </div>
            <div class="logo-content mt-3 col-md-12">
                    <img src="#"
                        class="big-logo invoice_logo img_setting" id="categoryIcon" width="200px">
            </div>
        </div>
    </div>
    <div class="ss-field col-md-4">

        <div class="form-check form-switch">
            <input type="hidden" name="trending" value="0">
            <input type="checkbox" name="trending" class="form-check-input input-primary" id="customCheckdef1trending" value="1">
            <label class="ss-label form-check-label" for="customCheckdef1trending">{{ __('Trending') }}</label>
        </div>
    </div>
    <div class="ss-field col-md-4">

        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            <input type="checkbox" class="form-check-input status" name="status"
                id="status" value="1">
            <label class="ss-label form-check-label" for="status">{{ __('Status') }}</label>
        </div>
    </div>
    <div class="ss-dialog__footer modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
    </div>
</div>
{!! Form::close() !!}
</div>
