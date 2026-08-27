<?php

namespace Workdo\PreOrder\Database\Seeders;

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
        $module = 'PreOrder';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'PreOrder';
        $data['product_main_description'] = '<p>Allow customers to reserve upcoming products in advance and boost anticipation with the Pre-Order Module.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Simplify Product Reservations.';
        $data['dedicated_theme_description'] = '<p>The Pre-Order Module in E-commerceGo allows businesses to manage product reservations with ease. This feature enables customers to secure upcoming or out-of-stock items, enhancing the shopping experience and maintaining sales momentum even when inventory is low.</p>';
        $data['dedicated_theme_sections'] = '[
                                                {
                                                    "dedicated_theme_section_image": "",
                                                    "dedicated_theme_section_heading": "Flexible Pre-Order Settings",
                                                    "dedicated_theme_section_description": "Businesses can customize pre-order options for specific products or categories. Set pre-order availability dates, display estimated delivery times, and manage stock limits effortlessly. This flexibility ensures that pre-order functionality aligns with your business goals and operational capabilities. ",
                                                    "dedicated_theme_section_cards": {
                                                    "1": {
                                                        "title": "Seamless Customer Communication",
                                                        "description": "Keep customers informed throughout the pre-order process. From automatic email notifications confirming their pre-orders to updates about availability and shipping timelines, the module ensures transparency and builds trust with your audience."
                                                    },
                                                    "2": {
                                                    "title": "Boost Engagement and Sales",
                                                        "description": "Pre-orders create excitement for upcoming launches and encourage early commitments from customers. By showcasing products before their official release, businesses can gauge demand and plan inventory better, reducing risks of overstock or understock situations."
                                                    },
                                                    "3": {
                                                    "title": "Analytics for Pre-Order Performance",
                                                        "description": "Track the success of your pre-order campaigns with built-in analytics tools. Monitor pre-order volumes, customer behavior, and revenue trends to make data-driven decisions for future launches. This insight helps refine strategies and optimize the pre-order process over time."
                                                    }
                                                    }
                                                }
                                            ]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"}]';
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
