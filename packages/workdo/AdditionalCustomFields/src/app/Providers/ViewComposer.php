<?php

namespace Workdo\AdditionalCustomFields\app\Providers;

use Workdo\AdditionalCustomFields\app\Models\AdditionalCustomFields as AdditionalCustomFieldsModel;
use Illuminate\Support\ServiceProvider;
use Workdo\AdditionalCustomFields\app\Models\ProductAdditionalCustomField;
use Illuminate\Support\Facades\Cache;
use App\Models\Product; 
use App\Models\Store; 

class ViewComposer extends ServiceProvider
{

    public function boot()
    {
        view()->composer(['product.create'], function ($view) {
            if (module_is_active('AdditionalCustomFields')) {
                        $store_id = getcurrentstore();
                        $fields = AdditionalCustomFieldsModel::where('store_id', $store_id)
                        ->get();

                    $view->getFactory()->startPush('CustomFieldsView', view('additional-custom-fields::product.show', compact('fields')));
            }
        });
        view()->composer([ 'product.edit'], function ($view) {
            if (module_is_active('AdditionalCustomFields')) {
                $store_id = getcurrentstore();
                $product_id = request()->route('product') ? request()->route('product')->id : null;
        
                // Fetch additional custom fields
                $fields = AdditionalCustomFieldsModel::where('store_id', $store_id)
                    ->get();
        
                // Fetch product-specific custom fields with values
                $productFields = ProductAdditionalCustomField::where('store_id', $store_id)
                    ->where('product_id', $product_id)
                    ->get();
                $view->getFactory()->startPush('CustomFieldsEditView', view('additional-custom-fields::product.edit', compact('productFields','fields')));
            }
        }); 
        view()->composer(['front_end.pages.product-detail'], function ($view) {
        
            if (module_is_active('AdditionalCustomFields')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $product_slug = \Request::segment(3);
                $product = Product::where('slug', $product_slug)->first();
                if ($store) {
                    $productFields = ProductAdditionalCustomField::where('store_id', $store->id)
                    ->where('product_id', $product->id)
                    ->get();

                    $customFields = ProductAdditionalCustomField::where('store_id', $store->id)
                    ->where('product_id', $product->id)
                    ->get();
                        $view->getFactory()->startPush('AdditionalCustomFieldButton', view('additional-custom-fields::front_end.button', compact('productFields')));
                        $view->getFactory()->startPush('AdditionalCustomFieldForm', view('additional-custom-fields::front_end.form', compact('customFields')));
                }
            }
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()  
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
