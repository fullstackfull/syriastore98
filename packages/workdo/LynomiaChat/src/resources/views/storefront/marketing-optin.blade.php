{{--
    The marketing opt-in on a store's checkout.

    UNCHECKED, ALWAYS. There is no `checked` attribute and no setting that can add one: a
    pre-ticked consent box is not consent, and the capture service treats an absent parameter
    as no. The label says who is asking and what for, because "subscribe" on its own does not
    tell a shopper which store will message them or that they can stop.

    NO STYLING BEYOND WHAT THE CHECKOUT ALREADY USES. This renders inside the merchant's own
    checkout form, in whichever theme they run, so it borrows the page's classes rather than
    bringing its own and looking like somebody else's component.
--}}
<div class="mt-4" id="lynomia-marketing-optin">
    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
        <label for="lynomia_marketing_optin" class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox"
                   id="lynomia_marketing_optin"
                   name="{{ $optinField }}"
                   value="1"
                   class="mt-1">
            <span class="md:text-base text-sm">
                {{ __('Keep me updated with offers and news from :store', ['store' => $optinStoreName]) }}
                <span class="block text-xs text-gray-500 mt-1">
                    {{ __('You can stop these messages at any time by replying STOP or by asking us. This does not affect messages about your order.') }}
                </span>
            </span>
        </label>
    </div>
</div>
