<?php

namespace Workdo\CouponEmail\Database\Seeders;

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
        $module = 'CouponEmail';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Coupon Email';
        $data['product_main_description'] = '<p>Enhance your marketing strategy and boost customer engagement with the Email Coupon module of eCommerceGo SaaS. This feature empowers you to send targeted email coupons to your customers based on specific triggers, ensuring timely and personalized communication that drives conversions and increases loyalty.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Tailored Offers for Every Customer Journey';
        $data['dedicated_theme_description'] = '<p>With eCommerceGo SaaS, you have the flexibility to choose from three options to trigger email coupon delivery: user registration, first purchase, or specific order threshold. This allows you to tailor your offers to different stages of the customer journey, maximizing relevance and effectiveness. Whether you want to incentivize new sign-ups, reward first-time buyers, or encourage repeat purchases, our module has you covered.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration and Automation","dedicated_theme_section_description":"Gone are the days of manually sending out coupons to your customers. With eCommerceGo SaaS, the Email Coupon module automates the process, saving you time and effort. Simply set your triggers and coupon parameters, and let our platform handle the rest. Whether you`re managing a small business or a large enterprise, our seamless integration ensures that your email campaigns run smoothly and efficiently."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Increase Conversions and Customer Retention","dedicated_theme_section_description":"By delivering targeted coupons directly to your customers` inboxes, you can significantly increase conversion rates and drive repeat business. Studies show that personalized offers are more likely to be redeemed, leading to higher customer satisfaction and loyalty. With eCommerceGo SaaS, you can nurture customer relationships and keep them coming back for more with irresistible deals and discounts."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"CouponEmail"},{"screenshots":"","screenshots_heading":"CouponEmail"},{"screenshots":"","screenshots_heading":"CouponEmail"},{"screenshots":"","screenshots_heading":"CouponEmail"},{"screenshots":"","screenshots_heading":"CouponEmail"}]';
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
