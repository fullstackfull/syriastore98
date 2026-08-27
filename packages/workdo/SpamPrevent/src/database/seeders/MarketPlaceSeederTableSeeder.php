<?php

namespace Workdo\SpamPrevent\Database\Seeders;

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
        $module = 'SpamPrevent';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'SpamPrevent';
        $data['product_main_description'] = '<p>The Spam Prevention Module for eCommerceGo SaaS streamlines customer interaction by providing a convenient commenting system directly on product pages. Customers can easily leave comments, whether it is to share their experience with a product, ask questions, or provide feedback. With a simple click of a button, they can engage with your brand, fostering a sense of community and trust.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Efficient Admin Management';
        $data['dedicated_theme_description'] = '<p>Admins can efficiently manage customer comments both from the backend and the front end. From the backend dashboard, they can view and respond to comments, ensuring timely and personalized interactions. Additionally, our module allows admins to reply directly from the front end, saving time and streamlining the process.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Anonymous Admin Replies","dedicated_theme_section_description":"One unique feature of our module is the ability for admins to reply anonymously if desired. By clicking on the refresh button, admins can refresh the page without triggering a notification to the customer. This feature gives admins the flexibility to handle interactions discreetly, maintaining professionalism and privacy when needed."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Clean and Spam-Free Environment","dedicated_theme_section_description":"Say goodbye to spam with our Spam Prevention Module. We understand the frustration of dealing with unwanted and irrelevant comments cluttering your product pages. Our module filters out spammy comments, ensuring that only genuine customer feedback is visible. This creates a clean and spam-free environment where customers can engage with your products without distractions."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced User Experience","dedicated_theme_section_description":"<p>Ultimately, the Spam Prevention Module enhances the user experience on your eCommerce platform. By keeping the comments section clean and relevant, customers can easily find valuable information and engage with your brand. Prompt and personalized responses from admins foster trust and loyalty, leading to a positive overall experience for both customers and admins alike. Upgrade to our module today and elevate your eCommerce commenting experience!<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SpamPrevent"},{"screenshots":"","screenshots_heading":"SpamPrevent"},{"screenshots":"","screenshots_heading":"SpamPrevent"},{"screenshots":"","screenshots_heading":"SpamPrevent"},{"screenshots":"","screenshots_heading":"SpamPrevent"}]';
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
