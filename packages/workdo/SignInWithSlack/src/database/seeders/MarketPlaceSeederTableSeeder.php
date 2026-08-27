<?php

namespace Workdo\SignInWithSlack\Database\Seeders;

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
        $module = 'SignInWithSlack';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Sign In With Slack';
        $data['product_main_description'] = '<p>Sign In with Slack module is designed to provide a seamless integration with Slack, enabling you to access our platform with just a single click. By leveraging your existing Slack credentials, you can bypass the traditional sign-in process and gain instant access to all the powerful features and tools EcommerceGo offers. Saving you time and effort.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhanced Security and Compliance';
        $data['dedicated_theme_description'] = '<p>Security is a top priority at EcommerceGo, and our Slack integration is built with robust security measures in place. By using OAuth 2.0 for authentication, we ensure that your credentials are protected and that the sign-in process is secure.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"",
        "dedicated_theme_section_heading":"Streamlined Communication and Collaboration",
        "dedicated_theme_section_description":"The "Sign In with Slack" module not only simplifies access but also enhances communication and collaboration within your team. Once signed in, users can receive real-time notifications from EcommerceGo directly in their Slack channels. This integration keeps your team informed about important updates, order statuses, and customer inquiries without having to switch between platforms."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Easy Setup and Customization","dedicated_theme_section_description":"Setting up the "Sign In with Slack" integration is quick and straightforward. Our user-friendly interface guides you through the process, allowing you to connect your Slack workspace to EcommerceGo in just a few steps. Once connected, you can customize the integration to fit your specific needs, such as choosing which notifications to receive in Slack and defining user permissions."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SignInWithSlack"},
                                {"screenshots":"","screenshots_heading":"SignInWithSlack"},
                                {"screenshots":"","screenshots_heading":"SignInWithSlack"},
                                {"screenshots":"","screenshots_heading":"SignInWithSlack"},
                                {"screenshots":"","screenshots_heading":"SignInWithSlack"}]';
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
            if (!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', $module)->exists()) {
                MarketplacePageSetting::updateOrCreate(
                    [
                        'name' => $key,
                        'module' => $module

                    ],
                    [
                        'value' => $value
                    ]
                );
            }
        }
    }
}
