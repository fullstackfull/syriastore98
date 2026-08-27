<?php

namespace Workdo\RecentlyViewedProducts\Database\Seeders;

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
        $module = 'RecentlyViewedProducts';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'RecentlyViewedProducts';
        $data['product_main_description'] = '<p>In the fast-paced world of online shopping, it is easy to lose track of products you have shown interest in. With our innovative feature, your browsing history becomes a valuable resource, allowing you to effortlessly revisit items you have recently viewed. Whether you are comparing options, contemplating a purchase, or simply exploring, our module ensures that your shopping journey remains seamless and intuitive.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Effortless Access to Past Interactions';
        $data['dedicated_theme_description'] = '<p>Never miss a beat with Recently Viewed Products. Our module keeps track of the items you have recently browsed, providing you with quick and easy access to your past interactions. Whether you are returning to finalize a purchase or simply want to revisit a favorite product, our feature ensures that your recent discoveries are always just a click away. Say goodbye to endless scrolling and rediscover your favorites with ease.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Privacy and Security","dedicated_theme_section_description":"Rest assured that your privacy and security are our top priorities. While we track your recently viewed products to enhance your shopping experience, we do so with the utmost respect for your privacy. Your browsing history is kept confidential and is never shared with third parties without your consent. With eCommerceGo SaaS, you can shop with confidence, knowing that your data is protected every step of the way."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Shopping Experience","dedicated_theme_section_description":"At eCommerceGo SaaS, we understand the importance of a seamless shopping experience. That is why our Recently Viewed Products module is designed to enhance your journey from start to finish. By keeping track of your recent interactions, we empower you to pick up right where you left off, saving you time and effort as you navigate through our platform. Whether you are a frequent shopper or an occasional visitor, our feature ensures that your experience is consistently smooth and enjoyable."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Get Started Today","dedicated_theme_section_description":"<p>Experience the convenience of Recently Viewed Products and take your shopping experience to new heights. Whether you are browsing for inspiration, researching options, or ready to make a purchase, our module ensures that your recent interactions are always at your fingertips. Explore our platform and discover the difference that eCommerceGo SaaS can make in your online shopping journey.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"RecentlyViewedProducts"},{"screenshots":"","screenshots_heading":"RecentlyViewedProducts"},{"screenshots":"","screenshots_heading":"RecentlyViewedProducts"},{"screenshots":"","screenshots_heading":"RecentlyViewedProducts"},{"screenshots":"","screenshots_heading":"RecentlyViewedProducts"}]';
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
