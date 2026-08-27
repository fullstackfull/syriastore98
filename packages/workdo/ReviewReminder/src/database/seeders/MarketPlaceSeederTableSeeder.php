<?php

namespace Workdo\ReviewReminder\database\seeders;

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
        $data['product_main_heading'] = 'ReviewReminder';
        $data['product_main_description'] = '<p>The Review Reminder module of eCommerceGo SaaS simplifies the process of gathering valuable feedback from your customers. Once customers receive their orders, this module automatically sends them reminders to leave reviews. With this feature, you can effortlessly collect feedback, improve customer satisfaction, and boost sales.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Streamlined Feedback Collection';
        $data['dedicated_theme_description'] = '<p>With the Review Reminder module, collecting customer feedback becomes a seamless process. Customers receive timely reminders to share their thoughts and experiences with your products or services. This ensures that you consistently gather valuable insights, helping you understand customer preferences and areas for improvement.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Customizable Email Templates","dedicated_theme_section_description":"Customizing review reminder emails has never been easier. The module allows you to create email templates tailored to your brands voice and style. Whether you want to incentivize customers to leave reviews or simply thank them for their purchase, you have the flexibility to design emails that resonate with your audience."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Manage Review Reminder Settings","dedicated_theme_section_description":"Take control of your review reminder process with flexible settings. You can enable or disable the review reminder button according to your preferences. This feature allows you to adjust your feedback collection strategy based on factors like product launches, seasonal promotions, or inventory changes."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Centralized Review Management","dedicated_theme_section_description":"All customer reviews are recorded in the Review Reminder module, providing you with a centralized platform to manage feedback. You can easily access and analyze reviews to gain insights into product performance, customer satisfaction, and areas for enhancement. This centralized approach to review management helps you make data-driven decisions to improve your business."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ReviewReminder"},{"screenshots":"","screenshots_heading":"ReviewReminder"},{"screenshots":"","screenshots_heading":"ReviewReminder"},{"screenshots":"","screenshots_heading":"ReviewReminder"},{  "screenshots":"","screenshots_heading":"ReviewReminder"}]';
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
            if (!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'ReviewReminder')->exists()) {
                MarketplacePageSetting::updateOrCreate(
                    [
                        'name' => $key,
                        'module' => 'ReviewReminder',

                    ],
                    [
                        'value' => $value,
                    ]);
            }
        }
    }
}
