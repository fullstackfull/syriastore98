<?php

namespace Workdo\AdditionalFieldInCheckout\Database\Seeders;

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
        $module = 'AdditionalFieldInCheckout';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Additional Field In Checkout';
        $data['product_main_description'] = '<p>The AdditionalFieldinCheckout Module for eCommerceGo offers a powerful and flexible solution for enhancing your checkout process. With this module, you can effortlessly add new custom fields to the checkout page, tailored to your specific business needs. Whether you need text fields, the module provides a wide array of field types to choose from. This flexibility ensures that you can gather all the necessary information from your customers, making the checkout experience more comprehensive and personalized.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhanced Field Customization for User Guidance';
        $data['dedicated_theme_description'] = '<p>Customization is at the heart of the AdditionalFieldinCheckout Module. Each custom field can be meticulously tailored with specific labels and placeholder texts, guiding customers on what information is required. Furthermore, robust validation rules can be set to ensure data accuracy and reliability. You can mark fields as required, set character limits, and enforce format restrictions. The module also supports conditional logic, allowing fields to appear or disappear based on customer inputs, thereby streamlining the checkout process and enhancing user experience.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Efficient Field Management and Real-Time Updates","dedicated_theme_section_description":"Managing your custom fields is straightforward and efficient with the AdditionalFieldinCheckout Module. The module allows you to edit existing fields, updating their labels, placeholders, and validation rules to adapt to changing requirements. Fields can be easily reordered via a drag-and-drop interface, ensuring the most logical and user-friendly arrangement. Additionally, fields can be temporarily enabled or disabled without the need to delete them, offering flexibility for seasonal promotions or limited-time offers. All changes are reflected in real-time, ensuring a seamless and up-to-date checkout experience."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Leveraging Data Collection for Business Growth","dedicated_theme_section_description":"The AdditionalFieldinCheckout Module not only enhances the checkout process but also serves as a valuable tool for data collection. By adding custom fields, you can gather important customer information such as special delivery instructions, gift messages, or feedback. This data can be leveraged to segment your customer base, allowing for more personalized marketing and communication strategies. Furthermore, the module supports data export, enabling seamless integration with CRM systems and other business tools, thereby driving informed decision-making and business growth."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"User-Friendly Interface for Seamless Management","dedicated_theme_section_description":"<p>Designed with ease of use in mind, the AdditionalFieldinCheckout Module features an intuitive dashboard within the eCommerceGo admin panel. This user-friendly interface allows for easy management of all custom fields, from creation to modification and reordering. The real-time update capability ensures that any changes made are immediately reflected on the checkout page, maintaining a smooth and efficient checkout process. By simplifying the management of custom fields, the module helps you focus on enhancing the overall customer experience and optimizing your eCommerce operations.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"AdditionalFieldInCheckout"},{"screenshots":"","screenshots_heading":"AdditionalFieldInCheckout"},{"screenshots":"","screenshots_heading":"AdditionalFieldInCheckout"},{"screenshots":"","screenshots_heading":"AdditionalFieldInCheckout"},{"screenshots":"","screenshots_heading":"AdditionalFieldInCheckout"}]';
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
