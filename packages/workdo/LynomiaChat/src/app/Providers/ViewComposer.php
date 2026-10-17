<?php

namespace Workdo\LynomiaChat\app\Providers;

use Illuminate\Support\ServiceProvider;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\CheckoutConsentCapture;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Putting the marketing opt-in on a merchant's checkout, when they have asked for it.
 *
 * A VIEW COMPOSER PUSHING INTO A STACK is the platform's own convention for this
 * (AdditionalFieldInCheckout does exactly the same thing with `CustomFieldView`), and it is
 * the right one: the module adds a field to a page it does not own, without editing the
 * theme, and an installation that does not load this module renders an empty stack.
 *
 * THREE CONDITIONS, AND ALL OF THEM ARE OFF BY DEFAULT. The integration must be enabled, the
 * store must hold an ACTIVE link, and the merchant must have switched the opt-in on. A field
 * that appeared on a live checkout because somebody connected an integration would be this
 * module changing a merchant's storefront without being asked.
 *
 * BOTH CHECKOUT TEMPLATES, because the storefront has two and a merchant's theme decides
 * which they get. They include the same hook file, so one stack reaches both.
 */
class ViewComposer extends ServiceProvider
{
    public function boot(): void
    {
        view()->composer(
            ['front_end.pages.checkout', 'front_end.common.checkout.checkout_form'],
            function ($view) {
                if (! config('lynomia.enabled')) {
                    return;
                }

                $store = $view->getData()['store'] ?? null;

                if ($store === null || empty($store->id)) {
                    return;
                }

                $link = LynomiaStoreLink::forStore($store->id)->first();

                if ($link === null || $link->state !== LinkState::ACTIVE || ! $link->checkout_optin_enabled) {
                    return;
                }

                $view->getFactory()->startPush(
                    'LynomiaMarketingOptIn',
                    view('lynomia-chat::storefront.marketing-optin', [
                        'optinField' => CheckoutConsentCapture::FIELD,
                        'optinStoreName' => $store->name ?? '',
                    ])
                );
            }
        );
    }
}
