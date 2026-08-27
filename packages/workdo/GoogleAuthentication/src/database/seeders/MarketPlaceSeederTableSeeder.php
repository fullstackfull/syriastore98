<?php

namespace Workdo\GoogleAuthentication\database\seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Workdo\LandingPage\Entities\MarketplacePageSetting;

class MarketPlaceSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Google Authentication';
        $data['product_main_description'] = '<p>Integrate Google Authentication with eCommerceGo to streamline user access and enhance security. By leveraging Google’s robust authentication system, you make it easier for users to sign in with their existing Google accounts, reducing the need for additional passwords and usernames. This simplifies the login process and minimizes potential barriers for new users.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Stay Up-to-Date';
        $data['dedicated_theme_description'] = '<p>By using Google Authentication, you benefit from ongoing updates and improvements made by Google’s security team. This ensures that your authentication system stays current with the latest security protocols and features. Regular updates and enhancements provide peace of mind that your platform remains secure and efficient as new security challenges emerge.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Streamline Management","dedicated_theme_section_description":"Integrating Google Authentication into your eCommerceGo platform simplifies user management for administrators. You can easily manage and monitor user access without the complexity of handling separate authentication systems. This integration helps in maintaining a clean and organized user database, facilitating smoother operations and better user support."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhance Security","dedicated_theme_section_description":"Google Authentication brings advanced security features to your eCommerceGo platform. With features like two-factor authentication and secure token management, you ensure that user accounts are protected against unauthorized access. This added security layer helps safeguard sensitive information and boost overall user confidence in your platform."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Improve User Experience","dedicated_theme_section_description":"Providing a seamless login experience is crucial for user satisfaction. Google Authentication allows users to quickly and effortlessly access their accounts without remembering multiple passwords. This ease of use can lead to increased user engagement and lower bounce rates, as users are less likely to experience frustration with the sign-in process."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Google Authentication"},{"screenshots":"","screenshots_heading":"Google Authentication"},{"screenshots":"","screenshots_heading":"Google Authentication"},{"screenshots":"","screenshots_heading":"Google Authentication"},{  "screenshots":"","screenshots_heading":"Google Authentication"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'GoogleAuthentication')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'GoogleAuthentication'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
