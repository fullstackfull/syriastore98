<?php

namespace Workdo\SignInWithTwitter\Database\Seeders;

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
        $module = 'SignInWithTwitter';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Sign-In With Twitter';
        $data['product_main_description'] = '<p>For eCommerce businesses, a seamless user experience is crucial. The "Sign in with Twitter" module for eCommerceGo SaaS provides a powerful tool to achieve this. By allowing customers to sign in using their existing Twitter accounts, you eliminate the need for lengthy registration forms and password creation, significantly reducing signup friction. This translates to quicker signups and higher conversion rates, ultimately leading to more sales.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Reach New Audiences and Deepen Engagement';
        $data['dedicated_theme_description'] = '<p>Social media plays a vital role in today online landscape. Integrating Twitter login allows you to leverage the power of social media within your eCommerce store. Existing Twitter users can easily discover and interact with your brand, potentially leading to new customer acquisition. Additionally, it fosters deeper engagement with existing customers who are already active on Twitter.</p>';
        $data['dedicated_theme_sections'] ='[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Peace of Mind with OAuth Authentication","dedicated_theme_section_description":"Security is paramount for both you and your customers. The Sign in with Twitter module utilizes secure OAuth authentication. This industry-standard protocol ensures that only authorized Twitter accounts can be used to log in, safeguarding your store and customer data. The integration process is also streamlined, requiring minimal configuration within your eCommerceGo admin panel."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Unlock the Benefits of Social Login","dedicated_theme_section_description":"By integrating the Sign in with Twitter module, you can create a more user-friendly and social shopping experience for your customers. This translates to increased signups, faster logins, potentially higher customer acquisition, and ultimately, a boost to your bottom line. Take advantage of this powerful feature and start reaping the benefits today!"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Sign-In With Twitter"},{"screenshots":"","screenshots_heading":"Sign-In With Twitter"},{"screenshots":"","screenshots_heading":"Sign-In With Twitter"},{"screenshots":"","screenshots_heading":"Sign-In With Twitter"},{  "screenshots":"","screenshots_heading":"Sign-In With Twitter"}]';
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
