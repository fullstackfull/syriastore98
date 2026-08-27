<?php

namespace Workdo\AdditionalFieldInCheckout\app\Listeners;

use App\Events\AddAdditionalFields;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Workdo\AdditionalFieldInCheckout\app\Models\CheckoutAdditionalField;

class AddAdditionalFieldsLis
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function handle(AddAdditionalFields $event)
    {
        if (module_is_active('AdditionalFieldInCheckout')) {
            $order = $event->order;
            $requests_data = $event->requests_data;
            $store = $event->store;
            $enable_additional_field = \App\Models\Utility::GetValueByName('enable_additional_field', $store->id);
            if (isset($enable_additional_field) && $enable_additional_field == 'on') {
                if (isset($requests_data['custom_field'])) {
                    foreach ($requests_data['custom_field'] as $value => $item) {
                        $fields       = new CheckoutAdditionalField();
                        $fields->order_id = $order->product_order_id ?? null;
                        $fields->field_name = $item['field_name'] ?? null;
                        $fields->value = $item['field_value'] ?? null;
                        $fields->status = $item['visible_option'] ?? null;
                        $fields->store_id = $store->id;
                        $fields->save();
                    }
                }
            }
        }
    }
}
