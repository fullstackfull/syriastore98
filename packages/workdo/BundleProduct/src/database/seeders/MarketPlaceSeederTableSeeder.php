<?php

namespace Workdo\BundleProduct\Database\Seeders;

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
        $module = 'BundleProduct';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'BundleProduct';
        $data['product_main_description'] = '<p>Gone are the days of tedious, time-consuming shopping experiences. With Bundle Products, we redefine convenience by bringing together all your essential items into one comprehensive package. Whether you are looking to revamp your wardrobe, upgrade your skincare routine, or stock up on household essentials, our bundles offer everything you need in one convenient click. Say goodbye to endless scrolling and hello to streamlined shopping experiences.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Maximize Savings, Minimize Hassle';
        $data['dedicated_theme_description'] = '<p>Saving money has never been easier with our Bundle Product module. By bundling products together, we unlock exclusive discounts and promotions that allow you to maximize your savings with every purchase. Why pay full price for individual items when you can enjoy significant discounts by bundling? Say goodbye to buyer is remorse and hello to smart, savvy shopping.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Tailored to Perfection","dedicated_theme_section_description":"We understand that every shopper has unique preferences and needs. That is why our Bundle Product module empowers you to customize your bundles to perfection. Mix and match your favorite products, choose your preferred sizes and colors, and create a bundle that is tailor-made just for you. Whether you are curating the perfect gift set or treating yourself to a well-deserved indulgence, our customizable options ensure that you get exactly what you want, every time."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Discover Endless Possibilities","dedicated_theme_section_description":"With Bundle Products, shopping becomes an adventure filled with endless possibilities. Each bundle presents an opportunity to explore new products, discover hidden gems, and indulge in exciting surprises. From deluxe beauty sets to curated culinary collections, our bundles offer a curated selection of products that are sure to delight and inspire. Who knows? You might just stumble upon your new favorite product with every bundle purchase."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Embrace Sustainable Shopping Practices","dedicated_theme_section_description":"<p>At eCommerceGo SaaS, we are committed to sustainability and environmental stewardship. That is why our Bundle Product module not only offers convenience and savings but also promotes sustainable shopping practices. By bundling products together, we minimize packaging waste and reduce our carbon footprint, contributing to a greener, more eco-friendly future. With each bundle purchase, you are not just saving money—you are also making a positive impact on the planet.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"BundleProduct"},{"screenshots":"","screenshots_heading":"BundleProduct"},{"screenshots":"","screenshots_heading":"BundleProduct"},{"screenshots":"","screenshots_heading":"BundleProduct"},{"screenshots":"","screenshots_heading":"BundleProduct"}]';
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
