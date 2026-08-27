<?php

namespace Workdo\GoogleLogin\Database\Seeders;

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
        $module = 'GoogleLogin';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Google Login';
        $data['product_main_description'] = "<p>At eCommerceGo, we are dedicated to enhancing your online store's user experience. One of the most effective ways to achieve this is through our seamless Google Login integration. This feature allows your customers to log in or sign up with their existing Google accounts, making the process quick, easy, and secure. By leveraging this popular authentication method, you can significantly improve user satisfaction and retention.</p>";
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2>Effortless Registration and Login</h2>';
        $data['dedicated_theme_description'] = '<p>Gone are the days when users had to remember multiple usernames and passwords. With Google Login integration, your customers can use their Google credentials to access your site. This not only simplifies the registration process but also speeds up the login experience, reducing the barriers to entry for new users. A faster, more convenient login process means more users will complete their registration, leading to higher conversion rates and more repeat visitors.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Security and Trust","dedicated_theme_section_description":"<p>Security is a top priority for any online business, and Google Login integration provides an added layer of protection. By using Google\'s robust authentication system, you can ensure that your customer\'s data is handled with the highest security standards. This integration helps in preventing fraud and unauthorized access, giving your users peace of mind and building trust in your brand.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless User Experience Across Devices","dedicated_theme_section_description":"<p>In today\'s multi-device world, consistency is key. Google Login integration offers a seamless experience across all devices, whether your customers are accessing your store via desktop, tablet, or mobile. This consistent user experience can increase engagement and satisfaction, as customers can effortlessly log in and shop from any device without the need to re-enter their credentials.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Boost Your Business with Analytics","dedicated_theme_section_description":"<p>Integrating Google Login not only benefits your users but also provides valuable insights for your business. By tracking login data and user behavior, you can gain a deeper understanding of your customer base. This information can be used to tailor marketing efforts, improve user experience, and drive sales. With Google Login integration, you\'re not just simplifying the login process; you\'re also empowering your business with data-driven decisions.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"GoogleLogin"},{"screenshots":"","screenshots_heading":"GoogleLogin"},{"screenshots":"","screenshots_heading":"GoogleLogin"},{"screenshots":"","screenshots_heading":"GoogleLogin"}]';
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
