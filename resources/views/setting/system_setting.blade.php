<!--Start System Setting-->
<div class="ss-card" id="System_Setting">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('System Settings') }}</h2>
        </div>
    </div>
    {{-- TWO FORMS WERE OPENED HERE, back to back: a `Form::open` immediately followed by a
         `Form::model` for the same route, which emits a `<form>` nested inside a `<form>`.
         Nested forms are invalid HTML — the browser drops the inner one, and which of the two
         the submit button belongs to is then a parser decision rather than a choice. Only the
         `Form::model` is needed: it is what binds `$setting` to the fields. --}}
    {{ Form::model($setting, ['route' => 'system.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
    <div class="ss-card__body">
        
            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="ss-field changeLanguage">
                        {{-- GAP-060 WS5. "DEFAULT LANGUAGE" IS THE SHOPPERS' LANGUAGE, NOT YOURS.

                             `SettingController::SystemSettings()` lines 1208-1224 write
                             `users.default_language` on the signed-in row AND
                             `stores.default_language` on the store. The STOREFRONT reads the store
                             column -- `ViewServiceProvider:173` does
                             `Cookie::get('LANGUAGE') ?? $store->default_language` then
                             `App::setLocale()`, and `DocumentDirection::localeFor()` reads the same
                             column for the `lang` attribute an order document declares.

                             THE PANEL'S OWN LANGUAGE IS A DIFFERENT COLUMN. `SetLocate` reads
                             `auth()->user()->language`, which this handler never touches. MEASURED:
                             saving `ar` here left `stores.default_language` and
                             `users.default_language` at 'ar' and `users.language` at 'en', so the
                             merchant who saved it kept an English panel and their shoppers did not.
                             That is the whole reason the sentence exists. --}}
                        <label class="ss-label" for="default_language">{{ __('Default Language') }}</label>
                        <select name="default_language" id="default_language" class="ss-input"
                                aria-describedby="default_language-help">
                            @foreach (\App\Models\Utility::languages() as $code => $language)
                            <option @if (\Auth::user()['default_language']==$code) selected @endif value="{{ $code }}">
                                {{ ucFirst($language) }}</option>
                            @endforeach
                        </select>

                        <x-ss.field-help for="default_language">{{ __('The language your storefront opens in for shoppers, and the language your order documents declare. It does not change the language of this admin panel, which follows the language on your own account.') }}</x-ss.field-help>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="ss-field">
                    {{-- GAP-060 WS5. ONE READER IN THE WHOLE APPLICATION, and it is a storefront page.

                         Grepped `app/` for `defult_timezone`: four hits are the defaults bag in
                         `defaultSetting()` and `Utility`, two return it to the Flutter app as
                         `timezone`, and the only place that APPLIES it is
                         `HomeController:1437-1438` -- the storefront product page, which does
                         `date_default_timezone_set($defaultTimeZone)` before working out
                         `date('Y-m-d H:i:s A')`.

                         AND SIX PLACES OVERRIDE IT WITH A LITERAL: `Product.php:208`, `:282`,
                         `:336`, `:522`, `ProductVariant.php:46` and `FlashSale.php:52` each call
                         `date_default_timezone_set('Asia/Kolkata')` themselves, which is why the
                         sentence says the product and flash-sale windows use a fixed zone of their
                         own instead of implying this control reaches them.

                         Nothing in the merchant panel restates a date in this zone: panel dates go
                         through `Utility::dateFormat()`, which reads `site_date_format` and formats
                         a timestamp, so the sentence points at that control by its real label. --}}
                    <label class="ss-label" for="defult_timezone">{{ __('Default Timezone') }}</label>
                    <select name="defult_timezone" id="defult_timezone" class="ss-input"
                            aria-describedby="defult_timezone-help">
                        <option value="" @if (isset($setting['defult_timezone']) && $setting['defult_timezone']=='' ) selected="selected" @endif>
                            {{ __('Select Timezone') }}</option>
                            @foreach ($timezones as $key => $timezone)
                            <option value="{{ $key }}" @if (isset($setting['defult_timezone']) && $setting['defult_timezone'] == $key ) selected="selected" @endif>
                            {{ ucFirst($timezone) }}</option>
                            @endforeach
                    </select>

                    <x-ss.field-help for="defult_timezone">{{ __('Used by your storefront when it works out the current date on a product page. Dates in this panel are printed with the Date Format setting instead and are not restated in this zone, and the product and flash sale time windows use a fixed zone of their own.') }}</x-ss.field-help>
                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="ss-field">
                    {{-- GAP-060 WS5. THIS ONE REALLY DOES DRIVE THE PANEL, which is why it is
                         instructed and its Time Format sibling below is NOT.

                         `Utility::dateFormat()` reads this name and hands it to
                         `formatMomentForLocale()`, which uses Carbon's `translatedFormat()` -- the
                         same `date()` characters with the TEXTUAL ones translated, so an Arabic
                         merchant gets `تشرين الثاني` rather than `Nov`. 34 references across the
                         panel: `OrderDataTable:588` and `:602`, `CouponDataTable:37`,
                         `customer/view.blade.php:87`, `:166`, `:169` and the rest.

                         `site_time_format` HAS NO SUCH FUNCTION -- there is no `Utility::timeFormat`
                         anywhere in `app/`, `resources/views/` or `packages/workdo/`, and nothing
                         formats a time with the value -- so it is left without an instruction and
                         `DeferredChoiceGuidanceTest` asserts both that absence and the condition
                         that would close it. --}}
                    <label class="ss-label" for="site_date_format">{{ __('Date Format') }}</label>
                    <select type="text" name="site_date_format" class="ss-input" id="site_date_format"
                            aria-describedby="site_date_format-help">
                        <option value="M j, Y" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='M j, Y' ) selected="selected" @endif>
                            {{ __('Jan 1,2015') }}</option>
                        <option value="d-m-Y" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='d-m-Y' ) selected="selected" @endif>
                            {{ __('DD-MM-YYYY') }}</option>
                        <option value="m-d-Y" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='m-d-Y' ) selected="selected" @endif>
                            {{ __('MM-DD-YYYY') }}</option>
                        <option value="Y-m-d" @if (isset($setting['site_date_format']) && $setting['site_date_format']=='Y-m-d' ) selected="selected" @endif>
                            {{ __('YYYY-MM-DD') }}</option>
                    </select>

                    <x-ss.field-help for="site_date_format">{{ __('The pattern every date in this panel is printed with, including the order list, coupon expiry dates and customer history, and the month name is translated into your own language.') }}</x-ss.field-help>
                    </div>
                </div>

                <div class="col-md-6 col-12">
                    <div class="ss-field">
                    <label class="ss-label" for="site_time_format">{{ __('Time Format') }}</label>
                    <select type="text" name="site_time_format" class="ss-input" id="site_time_format">
                        <option value="g:i A" @if (isset($setting['site_time_format']) && $setting['site_time_format']=='g:i A' ) selected="selected" @endif>
                            {{ __('10:30 PM') }}</option>
                        <option value="g:i a" @if (isset($setting['site_time_format']) && $setting['site_time_format']=='g:i a' ) selected="selected" @endif>
                            {{ __('10:30 pm') }}</option>
                        <option value="H:i" @if (isset($setting['site_time_format']) && $setting['site_time_format']=='H:i' ) selected="selected" @endif>
                            {{ __('22:30') }}</option>
                    </select>
                    </div>
                </div>
            </div>        
    </div>
    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
<!--End System Setting-->