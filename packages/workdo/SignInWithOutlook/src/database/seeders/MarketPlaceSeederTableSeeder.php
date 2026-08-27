<?php

namespace Workdo\SignInWithOutlook\Database\Seeders;

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
        $module = 'SignInWithOutlook';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Sign-In With Outlook';
        $data['product_main_description'] = '<p>Integrating Outlook Sign-In with eCommerceGo SaaS provides a seamless and secure way for users to access their accounts using their existing Microsoft credentials. This integration enhances the user experience by allowing customers to sign in quickly and effortlessly, leveraging the security and convenience of their Outlook accounts. By offering Outlook Sign-In, businesses can streamline the login process, reduce the friction associated with creating and remembering new credentials, and provide a familiar authentication method for users already comfortable with Microsoft services.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enabling Outlook Sign-In Integration';
        $data['dedicated_theme_description'] = '<p>To enable Outlook Sign-In within your eCommerceGo SaaS platform, you need to access the authentication settings in your admin dashboard. From there, select Outlook as an available sign-in method. The setup process involves configuring the necessary OAuth credentials and permissions, which Microsoft provides. Detailed documentation and support resources are available to guide you through this process, ensuring a smooth and straightforward integration. Once enabled, users can start signing in with their Outlook accounts, improving the overall user experience.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Configuring Authentication Settings","dedicated_theme_section_description":"After enabling Outlook Sign-In, the next step is to configure the authentication settings to ensure optimal security and user management. This includes entering your Microsoft Azure application credentials, setting up callback URLs, and defining user roles and permissions within eCommerceGo SaaS. Proper configuration is crucial for maintaining a secure authentication process and ensuring that user data is protected. Comprehensive guides and best practices are provided to help you navigate this configuration, ensuring that your integration is both secure and efficient."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"User Experience with Outlook Sign-In","dedicated_theme_section_description":"With Outlook Sign-In integrated into eCommerceGo SaaS, users will benefit from a streamlined and familiar login process. During sign-in, users can choose to authenticate via their Outlook accounts, which redirects them to the secure Microsoft login page. After successfully entering their credentials, they are redirected back to eCommerceGo SaaS, logged in and ready to use the platform. This seamless process reduces login friction, enhances security, and increases user satisfaction. Additionally, users who prefer Outlook will appreciate the convenience of using their existing credentials."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Sign-In With Outlook"},{"screenshots":"","screenshots_heading":"Sign-In With Outlook"},{"screenshots":"","screenshots_heading":"Sign-In With Outlook"},{"screenshots":"","screenshots_heading":"Sign-In With Outlook"},{  "screenshots":"","screenshots_heading":"Sign-In With Outlook"}]';
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
