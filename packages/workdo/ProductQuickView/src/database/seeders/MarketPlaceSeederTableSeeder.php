<?php

namespace Workdo\ProductQuickView\Database\Seeders;

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

        // $this->call("OthersTableSeeder");
        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Product Quick View';
        $data['product_main_description'] = '<p>In the dynamic landscape of online shopping, convenience reigns supreme. With Product Quick View by eCommerceGo, we bring you a seamless solution to enhance the browsing experience for your customers. Our innovative feature allows shoppers to preview essential product details without the hassle of navigating away from the main page. Whether they are browsing through a vast catalog or exploring new arrivals, Product Quick View offers a swift and intuitive way to access key information with ease.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Effortless Access to Product Details';
        $data['dedicated_theme_description'] = '<p>Gone are the days of multiple page loads to view product details. With Product Quick View, shoppers can simply hover over a product image or click on a designated button to instantly access essential information in a convenient pop-up window. From product descriptions to pricing, availability, and color/size options, everything they need to make an informed decision is right at their fingertips. This streamlined approach eliminates friction in the browsing journey, empowering users to explore your offerings effortlessly.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Faster Decision-Making","dedicated_theme_section_description":"The ability to preview product details at a glance facilitates faster decision-making, helping users move swiftly from consideration to purchase. With Product Quick View, shoppers can assess vital information without committing to a full-page view, enabling them to make informed choices in less time. This accelerated decision-making process not only enhances user satisfaction but also boosts conversion rates, driving revenue growth for your eCommerce business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration with eCommerceGo","dedicated_theme_section_description":"Product Quick View seamlessly integrates with the eCommerceGo platform, offering a hassle-free solution to enhance your online store is functionality. Whether you are a small boutique or a large-scale retailer, our feature-rich module is designed to cater to your specific needs. With easy customization options and responsive design, you can tailor Product Quick View to align with your brand identity and provide a cohesive shopping experience across all devices."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Unlock the Power of Convenience","dedicated_theme_section_description":"<p>Embrace the power of convenience and elevate your online shopping experience with Product Quick View by eCommerceGo. Simplify the browsing journey for your customers, drive engagement, and increase conversions with our intuitive module. Take the next step towards enhancing your eCommerce business by integrating Product Quick View into your platform today.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Product Quick View"},{"screenshots":"","screenshots_heading":"Product Quick View"},{"screenshots":"","screenshots_heading":"Product Quick View"},{"screenshots":"","screenshots_heading":"Product Quick View"},{  "screenshots":"","screenshots_heading":"Product Quick View"}]';
        $data['addon_heading'] = 'Why choose dedicated modules for Your Business?';
        $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modules for Your Business?';
        $data['whychoose_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['pricing_plan_heading'] = 'Empower Your Workforce with EcommerceGo';
        $data['pricing_plan_description'] = '<p>Access over Premium Add-ons for Product Quick View, Product Review, Product Quick-view, and more, all in one place!</p>';
        $data['pricing_plan_demo_link'] = '#';
        $data['pricing_plan_demo_button_text'] = 'View Live Demo';
        $data['pricing_plan_text'] = '{"1":{"title":"Pay-as-you-go"},"2":{"title":"Unlimited installation"},"3":{"title":"Secure cloud storage"}}';
        $data['whychoose_sections_status'] = 'on';
        $data['dedicated_theme_section_status'] = 'on';

        foreach($data as $key => $value){
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'ProductQuickView')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'ProductQuickView'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
