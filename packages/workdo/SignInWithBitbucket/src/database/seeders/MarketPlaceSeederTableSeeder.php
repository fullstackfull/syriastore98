<?php

namespace Workdo\SignInWithBitbucket\Database\Seeders;

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
        $module = 'SignInWithBitbucket';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Sign-In With BitBucket';
        $data['product_main_description'] = '<p>Efficient code management is critical to the success of any e-commerce platform, and EcommerceGo SaaS is proud to offer seamless integration with Bitbucket, a leading Git-based repository management solution. Our Bitbucket module enables developers to easily collaborate, track changes, and maintain high-quality code, all within the familiar interface of EcommerceGo. This integration ensures that your development workflow remains smooth and uninterrupted, allowing your team to focus on delivering top-notch e-commerce experiences to your customers.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Secure and Scalable Version Control';
        $data['dedicated_theme_description'] = '<p>Security and scalability are paramount in the fast-paced world of e-commerce. The Bitbucket module in EcommerceGo provides secure, scalable version control, ensuring that your codebase is protected against unauthorized access and potential breaches. Bitbucket’s robust permission settings and branch restrictions give you granular control over who can access and modify your code, while its scalability ensures that your repository can grow alongside your business without compromising performance.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration and Easy Setup","dedicated_theme_section_description":"Setting up the Bitbucket module in EcommerceGo is a straightforward process, designed to minimize downtime and disruption. Our intuitive interface guides you through the integration steps, ensuring that your Bitbucket repositories are linked and ready for use in no time. Once set up, the integration works seamlessly, allowing you to manage your repositories, track changes, and collaborate on code directly from within the EcommerceGo platform. This seamless integration helps you maintain a cohesive and efficient development workflow, ultimately driving better outcomes for your e-commerce business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Collaboration and Code Review","dedicated_theme_section_description":"The Bitbucket module in EcommerceGo facilitates enhanced collaboration among your development team. With features like pull requests, inline comments, and detailed code reviews, team members can work together more effectively, catching potential issues early and ensuring that only the best code makes it to production. This collaborative approach not only improves code quality but also fosters a culture of continuous improvement and knowledge sharing within your team."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Sign-In With BitBucket"},{"screenshots":"","screenshots_heading":"Sign-In With BitBucket"},{"screenshots":"","screenshots_heading":"Sign-In With BitBucket"},{"screenshots":"","screenshots_heading":"Sign-In With BitBucket"},{  "screenshots":"","screenshots_heading":"Sign-In With BitBucket"}]';
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
