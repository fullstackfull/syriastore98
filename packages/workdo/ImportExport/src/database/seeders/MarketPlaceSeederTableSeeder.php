<?php

namespace Workdo\ImportExport\Database\Seeders;

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
        $module = 'ImportExport';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'ImportExport';
        $data['product_main_description'] = '<p>eCommerceGo SaaS offers a robust Import/Export Module designed to streamline the complexities of global trade. Whether you are importing raw materials or exporting finished products, our platform simplifies the process with user-friendly tools. Easily manage data transfer and product shipments across borders, ensuring smooth transactions and compliance with international trade regulations. From uploading supplier catalogs to exporting customer orders, our module supports seamless operations to enhance your business efficiency.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Comprehensive Data Management';
        $data['dedicated_theme_description'] = '<p>Take control of your import and export processes with eCommerceGo SaaS. Our module facilitates easy data handling, allowing you to upload, update, and synchronize information effortlessly. Maintain accuracy in inventory records, pricing details, and shipment statuses to optimize supply chain management. By centralizing data management, businesses can minimize errors, improve decision-making, and respond promptly to market demands.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Flexible Configuration Options","dedicated_theme_section_description":"Customize import and export settings to align with your business strategies and operational requirements. eCommerceGo SaaS empowers users to adjust currency conversions, shipping methods, and tax considerations with ease. Adapt swiftly to changing market conditions and regulatory frameworks, ensuring compliance and optimizing cost-efficiency in global trade operations. Our flexible configuration options cater to diverse business needs, supporting seamless integration with existing workflows."},

        {"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Monitoring and Analytics","dedicated_theme_section_description":"Gain actionable insights with real-time monitoring of import and export activities. Track shipment statuses, analyze sales trends, and forecast demand to make informed business decisions. eCommerceGo SaaS provides comprehensive analytics tools that empower businesses to optimize inventory levels, improve supply chain efficiency, and enhance customer satisfaction. Monitor performance metrics and streamline operations to drive growth and profitability in international markets."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ImportExport"},{"screenshots":"","screenshots_heading":"ImportExport"},{"screenshots":"","screenshots_heading":"ImportExport"},{"screenshots":"","screenshots_heading":"ImportExport"},{"screenshots":"","screenshots_heading":"ImportExport"}]';
        $data['addon_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modulesfor Your Business?';
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
