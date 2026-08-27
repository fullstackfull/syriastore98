<?php

namespace Workdo\Signinwithgithub\Database\Seeders;

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
        $module = 'Signinwithgithub';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Signinwithgithub';
        $data['product_main_description'] = "<p>The \"Sign In with GitHub\" module of eCommerceGo SaaS revolutionizes the authentication process for users. With this module, users can seamlessly log in to your eCommerce platform using their existing GitHub credentials. This eliminates the hassle of creating and managing yet another username and password combination, streamlining the login experience and reducing friction for users.
        </p>";
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2>Enhanced Security Measures</h2>';
        $data['dedicated_theme_description'] = '<p>Integrating GitHub authentication into your eCommerce platform enhances security measures significantly. GitHub employs robust security protocols, including two-factor authentication and encryption, to protect user accounts. By leveraging GitHub\'s security infrastructure, you can ensure that user data remains safe and secure, guarding against unauthorized access and potential data breaches.</p>';
       $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Convenience for Developers","dedicated_theme_section_description":"<p>The \"Sign In with GitHub\" module also offers added convenience for developers and tech-savvy users. GitHub is a popular platform among developers for version control and collaboration, and many users already have GitHub accounts. By providing a familiar and convenient login option, you can attract more developers to your platform and facilitate their engagement with your eCommerce services.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Trust and Credibility","dedicated_theme_section_description":"<p>Integrating GitHub authentication into your eCommerce platform can also enhance trust and credibility among users. GitHub is widely regarded as a reputable and trustworthy platform within the developer community. By offering GitHub authentication, you signal to users that your platform prioritizes security and reliability, instilling confidence in their decision to sign up and interact with your eCommerce services.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"User-Friendly Experience","dedicated_theme_section_description":"<p>Overall, the \"Sign In with GitHub\" module of eCommerceGo SaaS delivers a user-friendly experience that prioritizes convenience, security, and trust. By streamlining the authentication process, enhancing security measures, and offering added convenience for developers, this module ensures that users can access your eCommerce platform quickly and securely. Embracing GitHub authentication not only simplifies the login experience but also reinforces your platform\'s commitment to providing a safe and reliable environment for online shopping.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Signinwithgithub"},{"screenshots":"","screenshots_heading":"Signinwithgithub"},{"screenshots":"","screenshots_heading":"Signinwithgithub"},{"screenshots":"","screenshots_heading":"Signinwithgithub"}]';
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
