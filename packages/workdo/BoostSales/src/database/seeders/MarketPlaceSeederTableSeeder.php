<?php

namespace Workdo\BoostSales\database\seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Workdo\LandingPage\Entities\MarketplacePageSetting;

class MarketPlaceSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Boost Sales';
        $data['product_main_description'] = '<p>Imagine watching your sales soar without lifting a finger. The eCommerceGo Boost Sales module makes it a reality. This powerful tool automates your marketing efforts and equips you with features to personalize customer experiences, recover abandoned carts, and optimize campaigns for maximum impact.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Effortless Marketing Automation';
        $data['dedicated_theme_description'] = '<p>Supercharge your online sales with eCommerceGos Boost Sales module – your ultimate tool for driving conversions and increasing revenue effortlessly!</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Never Lose a Sale Again","dedicated_theme_section_description":"Abandoned carts are a thing of the past. Automated campaigns recapture lost sales by reminding customers about forgotten items and enticing them to complete their purchase with irresistible incentives. Go beyond generic marketing blasts. Segment your audience based on their unique preferences and top purchases that resonate with each customer, increasing engagement and driving conversions."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Unleash Your Sales Potential with eCommerceGo Boost Sales Module","dedicated_theme_section_description":"Boost Sales module showcases top seller products or selected products, allowing customers to easily add all products to their cart with a single click. Admins can effortlessly choose which products to highlight, ensuring the best items are always front and center for your customers."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Boost Sales"},{"screenshots":"","screenshots_heading":"Boost Sales"},{"screenshots":"","screenshots_heading":"Boost Sales"},{"screenshots":"","screenshots_heading":"Boost Sales"},{  "screenshots":"","screenshots_heading":"Boost Sales"}]';
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

        foreach ($data as $key => $value) {
            if (!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'BoostSales')->exists()) {
                MarketplacePageSetting::updateOrCreate(
                    [
                        'name' => $key,
                        'module' => 'BoostSales'

                    ],
                    [
                        'value' => $value
                    ]
                );
            }
        }
    }
}
