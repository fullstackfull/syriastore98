<?php

namespace Workdo\Duo2FA\Database\Seeders;

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
        $data['product_main_heading'] = 'Duo2FA';
        $data['product_main_description'] = '<p>The Duo2FA Integration in eCommerceGo SaaS introduces an additional layer of security, ensuring that both admins and users are protected from unauthorized access. This module leverages the trusted Duo Two-Factor Authentication (2FA) system to provide a robust security solution. By incorporating Duo2FA, eCommerceGo enhances the overall security framework, safeguarding sensitive data and user accounts with minimal effort.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Easy Configuration with Required IDs';
        $data['dedicated_theme_description'] = '<p>Setting up the Duo2FA module in eCommerceGo SaaS is straightforward. Administrators need to add the required IDs for both the admin and user accounts. This initial configuration is quick and user-friendly, ensuring that businesses can implement enhanced security measures without extensive technical knowledge. The simple setup process allows businesses to promptly benefit from the added security without disrupting their operations.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Secure Admin and User Logins","dedicated_theme_section_description":"Once the Duo2FA module is configured, both admins and users will be prompted to enter a unique authentication code upon logging in. This two-step verification process ensures that only authorized individuals can access the platform. By requiring an additional code, which is typically sent to a user’s mobile device, the risk of unauthorized access is significantly reduced. This added layer of security is crucial for protecting sensitive business and customer data."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Protection Against Unauthorized Access","dedicated_theme_section_description":"The Duo2FA integration provides enhanced protection against unauthorized access and potential security breaches. With cyber threats becoming increasingly sophisticated, traditional password-based authentication methods are no longer sufficient. Duo2FA ensures that even if a password is compromised, unauthorized users will not be able to access the system without the second authentication factor. This dual-layered security approach is essential for maintaining the integrity of your eCommerce platform."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Duo2FA"},{"screenshots":"","screenshots_heading":"Duo2FA"},{"screenshots":"","screenshots_heading":"Duo2FA"},{"screenshots":"","screenshots_heading":"Duo2FA"},{  "screenshots":"","screenshots_heading":"Duo2FA"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'Duo2FA')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'Duo2FA'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
