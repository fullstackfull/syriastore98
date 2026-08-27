<?php

namespace Workdo\SidebarCustomization\Database\Seeders;

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
        $module = 'SidebarCustomization';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'SidebarCustomization';
        $data['product_main_description'] = '<p>The Sidebar Customization Add-On for eCommerceGo SaaS lets you design and personalize your website is sidebar with ease. This powerful feature ensures seamless navigation, enhances user experience, and aligns your site’s layout with your brand identity.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Fully Customizable Layout';
        $data['dedicated_theme_description'] = '<p>Tailor your sidebar to suit your website is needs. Add, remove, or reorder sections to prioritize the most relevant information for your users. Whether it’s quick links, product categories, or promotional banners, you have complete control over what your sidebar displays.</p>';
        $data['dedicated_theme_sections'] = '[
                                                {
                                                    "dedicated_theme_section_image": "",
                                                    "dedicated_theme_section_heading": "Brand-Consistent Design",
                                                    "dedicated_theme_section_description": "Maintain consistency across your website by customizing the sidebar’s appearance to reflect your brand. You can modify colors, fonts, and icons to create a cohesive design that enhances your site’s professionalism and visual appeal.",
                                                    "dedicated_theme_section_cards": {
                                                    "1": {
                                                        "title": "Improved User Experience",
                                                        "description": "A well-organized sidebar makes navigation easy for your customers. By highlighting key areas like new arrivals, popular products, or ongoing offers, you can guide users through your site effectively and boost engagement."
                                                    },
                                                    "2": {
                                                    "title": "Quick and Easy Setup",
                                                        "description": "Setting up the Sidebar Customization Add-On is simple and intuitive. With just a few clicks, you can create a sidebar that complements your website’s design and improves its functionality. It’s a small change that can have a big impact on customer satisfaction and sales."
                                                    }
                                                    }
                                                }
                                            ]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"},{"screenshots":"","screenshots_heading":"SidebarCustomization"}]';
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
