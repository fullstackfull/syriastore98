<?php

namespace Workdo\AdditionalCustomFields\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\LandingPage\Entities\MarketplacePageSetting;


class MarketPlaceSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        $module = 'AdditionalCustomFields';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'AdditionalCustomFields';
        $data['product_main_description'] = '<p>The Additional Custom Field module in eCommerceGo SAAS empowers businesses to enrich their products with customized fields, enabling them to gather precise and relevant information. This module is particularly beneficial for industries such as spas, clinics, or other appointment-based services where capturing detailed client information is essential. By leveraging this feature, admins can create tailored data fields that enhance the overall product configuration and booking experience, ensuring that the collected information aligns with their specific business needs.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enabling the Additional Custom Field Feature.';
        $data['dedicated_theme_description'] = '<p>Before utilizing the Additional Custom Field module, administrators must enable its functionality within the eCommerceGo SAAS settings. This critical step unlocks the modules tools, allowing admins to define and add custom fields. Activation is simple: navigate to the settings menu, locate the Additional Custom Field toggle, and switch it on. Once enabled, admins can customize fields for each product, paving the way for more detailed information capture during product creation and configuration.</p>';
        $data['dedicated_theme_sections'] = '[
            {
              "dedicated_theme_section_image": "",
              "dedicated_theme_section_heading": "Adding Custom Fields for Products",
              "dedicated_theme_section_description": "With the feature activated, administrators can introduce custom fields while creating or editing a product. These fields allow for capturing specific data that might be unique to a businesss operation, such as client preferences, additional instructions, or service-specific details. Custom fields can include text inputs, dropdown menus, or checkboxes, offering flexibility in data collection. Once defined during product creation, these fields become an integral part of the product details and ensure that all necessary information is gathered upfront."
            },
            {
              "dedicated_theme_section_image": "",
              "dedicated_theme_section_heading": "Enhancing Product Details with Custom Fields",
              "dedicated_theme_section_description": "The Additional Custom Field module is designed to optimize the administrative process of product configuration. By allowing admins to input additional field values during product creation, the module ensures that relevant details are automatically included in the Additional Custom Field page. This centralized repository of information reduces manual data entry and ensures consistency across records. The enhanced product details streamline internal workflows and improve communication between admins and their customers."
            },
            {
              "dedicated_theme_section_image": "",
              "dedicated_theme_section_heading": "Benefits of Streamlined Data Collection",
              "dedicated_theme_section_description": "The Additional Custom Field module simplifies data collection, reducing errors and the need for follow-up inquiries. By embedding custom fields directly into product creation, businesses can ensure that critical information is captured early in the process. This feature supports a smoother, more efficient workflow, enabling better customer service and operational excellence. In turn, businesses can deliver personalized experiences that enhance customer satisfaction and foster loyalty, making this module a valuable addition to any eCommerceGo SAAS implementation."
            }
          ]
          ';
            $data['dedicated_theme_sections_heading'] = '';
            $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"AdditionalCustomFields"},{"screenshots":"","screenshots_heading":"AdditionalCustomFields"},{"screenshots":"","screenshots_heading":"AdditionalCustomFields"},{"screenshots":"","screenshots_heading":"AdditionalCustomFields"},{"screenshots":"","screenshots_heading":"AdditionalCustomFields"}]';
            $data['addon_heading'] = 'Why choose dedicated modules for Your Business?';
            $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
            $data['addon_section_status'] = 'on';
            $data['whychoose_heading'] = 'Why choose dedicated modules for Your Business?';
            $data['whychoose_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
            $data['pricing_plan_heading'] = 'Empower Your Workforce with EcommerceGo';
            $data['pricing_plan_description'] = '<p>Access over Premium Add-ons for Product Enquiry, Product Review, Product Quick-view, and more, all in one place!</p>';
            $data['pricing_plan_demo_link'] = '#';
            $data['pricing_plan_demo_button_text'] = 'View Live Demo';
            $data['pricing_plan_text'] = '{"1":{"title":"Pay-as-you-go"},"2":{"title":"Unlimited installation"},"3":{"title":"Secure cloud storage"}}';
            $data['whychoose_sections_status'] = 'on';
            $data['dedicated_theme_section_status'] = 'on';
                                    

        foreach($data as $key => $value){
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', $module)->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => $module

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
