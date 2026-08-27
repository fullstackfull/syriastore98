<?php
namespace Workdo\Marketing\database\seeders;

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
        $data['product_main_heading'] = 'Marketing';
        $data['product_main_description'] = "<p>The Marketing Module in eCommerceGo SaaS is designed to revolutionize the way you promote your business. With its robust features, you can easily activate, generate, and modify business cards to suit your brand's needs. Whether you're a new business owner or looking to refresh your brand's image, this module provides you with the flexibility to design business cards that truly represent your company's identity.
</p>";
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhance Your Business Reach with the Marketing Module';
        $data['dedicated_theme_description'] = '<p>Boost your sales with our powerful marketing module, designed to streamline campaigns, track performance, and drive growth effortlessly.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Create and Edit Your Business Profile with Ease","dedicated_theme_section_description":"Starting a new business or managing multiple brands? The Marketing Module offers seamless options for creating new business profiles and editing them as your needs evolve. This flexibility ensures that your business information is always up to date, keeping your brand relevant in a competitive market. The module’s user-friendly interface makes it simple to add or update contact details, logos, and promotional messages, ensuring your brand always looks professional."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Choose from a Variety of Business Themes","dedicated_theme_section_description":"The Marketing Module also offers a range of customizable business themes to enhance your brand’s presentation. Whether you’re looking for something sleek and modern or classic and traditional, you can select from various themes that best align with your brand’s personality. Each theme is designed to maximize visual appeal and ensure consistency across all your marketing materials, helping you create a cohesive brand experience for your audience."},
        {"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Drive Growth with Customizable Marketing Campaigns","dedicated_theme_section_description":"Take your business to the next level by launching dynamic marketing campaigns directly from the platform. The Marketing Module allows you to create and run targeted campaigns that can reach your desired audience effectively. Use the platforms intuitive tools to customize your campaign strategies, set your budget, and track performance in real-time. This ensures your marketing efforts are both impactful and measurable, allowing for continuous improvement."}
        ,{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Streamline Your Marketing Efforts with All-in-One Tools","dedicated_theme_section_description":"With the Marketing Module, managing your business’s marketing efforts has never been easier. The module combines all necessary tools into one cohesive platform, allowing you to handle everything from business card creation to campaign management without ever leaving the platform. This integration simplifies your workflow, saves time, and reduces the complexity of managing multiple marketing tools, making it an essential solution for any business looking to grow."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Marketing"},{"screenshots":"","screenshots_heading":"Marketing"},{"screenshots":"","screenshots_heading":"Marketing"},{"screenshots":"","screenshots_heading":"Marketing"},{  "screenshots":"","screenshots_heading":"Marketing"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'Marketing')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'Marketing'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
