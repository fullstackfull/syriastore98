<?php

namespace Workdo\SignInWithLinkedIn\Database\Seeders;

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
        $module = 'SignInWithLinkedIn';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Sign-In With LinkedIn';
        $data['product_main_description'] = '<p>Integrating LinkedIn Sign-In with eCommerceGo SaaS provides users with a seamless and efficient login experience. By allowing users to sign in using their LinkedIn credentials, businesses can reduce the hassle of remembering multiple passwords and usernames. This single sign-on (SSO) capability simplifies access, ensuring that users can quickly and securely log into their accounts with just a few clicks.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhanced User Verification';
        $data['dedicated_theme_description'] = '<p>Using LinkedIn for authentication adds an extra layer of security and credibility to user accounts. LinkedIn profiles are typically verified and contain professional information, which helps in reducing fraudulent accounts and ensuring that the users logging into your platform are genuine. This not only enhances the trustworthiness of your user base but also helps in maintaining a more secure environment for all users.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration and Setup","dedicated_theme_section_description":"Implementing the LinkedIn Sign-In module in eCommerceGo SaaS is straightforward and hassle-free. The integration process is designed to be quick and easy, with comprehensive support and documentation available to guide you through each step. This ensures that businesses can start reaping the benefits of enhanced login functionality and improved user engagement without significant downtime or technical difficulties."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Improved User Experience","dedicated_theme_section_description":"With the LinkedIn Sign-In module, users benefit from a more streamlined and enjoyable experience. The convenience of using a familiar platform for login reduces friction and encourages more frequent use of your eCommerceGo SaaS platform. This improved user experience can lead to higher engagement and satisfaction rates, ultimately boosting customer loyalty and retention."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Valuable User Insights","dedicated_theme_section_description":"Integrating LinkedIn Sign-In also allows businesses to gain valuable insights into their user base. LinkedIn profiles provide a wealth of information, such as professional backgrounds, industries, and interests. By accessing this data (with user consent), businesses can tailor their services and marketing efforts to better meet the needs and preferences of their users, leading to more personalized and effective engagement strategies."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Sign-In With LinkedIn"},{"screenshots":"","screenshots_heading":"Sign-In With LinkedIn"},{"screenshots":"","screenshots_heading":"Sign-In With LinkedIn"},{"screenshots":"","screenshots_heading":"Sign-In With LinkedIn"},{  "screenshots":"","screenshots_heading":"Sign-In With LinkedIn"}]';
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
