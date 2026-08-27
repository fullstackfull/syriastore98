<?php

namespace Workdo\ProductReview\Database\Seeders;

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
        $module = 'ProductReview';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'ProductReview';
        $data['product_main_description'] = '<p>With Product Review functionality integrated into your eCommerce website powered by eCommerceGo SaaS, you are not just selling products; you are fostering a community of engaged customers. By providing a platform for users to share their thoughts and experiences, you empower them to become advocates for your brand. This open dialogue not only strengthens the bond between customers and your business but also provides valuable insights for potential buyers.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Social Proof for Informed Decisions';
        $data['dedicated_theme_description'] = '<p>User-generated reviews serve as powerful social proof, influencing the purchasing decisions of prospective buyers. By allowing customers to share their honest opinions and experiences, you provide valuable information that helps others make informed choices. Whether it is highlighting product benefits, sharing usage tips, or addressing potential concerns, user reviews contribute to a more transparent and authentic shopping environment.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Submission Process","dedicated_theme_section_description":"With eCommerceGo SaaS, submitting product reviews is effortless for customers. Our streamlined process allows users to submit ratings and detailed feedback directly on purchased items, eliminating barriers to participation. By simplifying the review submission process, you encourage greater engagement from your customer base, resulting in a more robust collection of user-generated content."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Moderation for Authenticity and Transparency","dedicated_theme_section_description":"Maintain authenticity and credibility by moderating product reviews within the eCommerceGo platform. Our moderation tools enable you to ensure that all reviews meet your standards for authenticity and relevance. Address any concerns raised by customers promptly and transparently, demonstrating your commitment to customer satisfaction and building trust in your brand."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Unlock the Power of Product Reviews with eCommerceGo","dedicated_theme_section_description":"<p>Harness the potential of user-generated content to drive sales and strengthen your brand reputation. With Product Review by eCommerceGo, you empower your customers to contribute to the shopping experience, while also gaining valuable insights to improve your products and services. Elevate your eCommerce business with this essential module and unlock new opportunities for growth and success.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Product Review"},{"screenshots":"","screenshots_heading":"Product Review"},{"screenshots":"","screenshots_heading":"Product Review"},{"screenshots":"","screenshots_heading":"Product Review"},{"screenshots":"","screenshots_heading":"Product Review"}]';
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
