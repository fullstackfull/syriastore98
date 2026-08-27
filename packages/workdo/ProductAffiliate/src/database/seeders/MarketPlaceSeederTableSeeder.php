<?php

namespace Workdo\ProductAffiliate\Database\Seeders;

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
        $module = 'ProductAffiliate';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'ProductAffiliate';
        $data['product_main_description'] = '<p>Think of our module as a way to turn your satisfied customers into enthusiastic promoters of your brand. We provide them with unique affiliate codes that they can easily share with their friends and family. When their affiliates make a purchase using these codes, both the referrer and the new customer receive rewards. It is a win-win situation that encourages your customers to spread the word about your products.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Understand What is Working and What is Not';
        $data['dedicated_theme_description'] = '<p>Tracking the performance of your affiliate program is crucial for making informed decisions. Our module comes with a user-friendly tracking system that helps you see which referrals are bringing in the most sales. This way, you can identify what is working well and what needs improvement, allowing you to refine your strategy for better results.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Offer Rewards That Matter to Your Customers","dedicated_theme_section_description":"We understand that every business has unique goals and preferences. That is why our module lets you customize the rewards you offer to your customers. Whether it is cash incentives, store credits, or special discounts, you have the flexibility to choose rewards that resonate with your audience and keep them engaged."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Simplify Your Workflow with Automation","dedicated_theme_section_description":"Running an affiliate program should not be complicated. Our module automates the entire process, from generating referral codes to tracking rewards and payouts. By automating these tasks, you can save time and effort, allowing you to focus on growing your business and providing excellent service to your customers."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Build Lasting Relationships and Loyalty","dedicated_theme_section_description":"<p>Rewarding affiliates is not just about acquiring new customers—it is also about fostering loyalty among your existing ones. When you show appreciation to your customers for referring others to your business, you deepen the bond between them and your brand. This loyalty leads to repeat purchases and long-term relationships that benefit your business in the long run.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ProductAffiliate"},{"screenshots":"","screenshots_heading":"ProductAffiliate"},{"screenshots":"","screenshots_heading":"ProductAffiliate"},{"screenshots":"","screenshots_heading":"ProductAffiliate"},{"screenshots":"","screenshots_heading":"ProductAffiliate"}]';
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
