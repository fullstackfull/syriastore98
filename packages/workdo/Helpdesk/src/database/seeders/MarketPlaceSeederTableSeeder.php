<?php

namespace Workdo\Helpdesk\Database\Seeders;

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
        $module = 'Helpdesk';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Helpdesk';
        $data['product_main_description'] = '<p>Welcome to our Helpdesk Module, a robust solution designed to facilitate seamless communication between businesses and super admins. Easily manage customer inquiries, bug reports, and product-related issues through our intuitive interface, complete with attachment support for comprehensive ticket handling. Engage in real-time chat for instant issue resolution and efficient collaboration, ensuring a superior customer service experience.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Introducing the Helpdesk Module of EcommerceGo SaaS';
        $data['dedicated_theme_description'] = '<p>Welcome to our Helpdesk Module, a robust solution designed to facilitate seamless communication between businesses and super admins. Easily manage customer inquiries, bug reports, and product-related issues through our intuitive interface, complete with attachment support for comprehensive ticket handling. Engage in real-time chat for instant issue resolution and efficient collaboration, ensuring a superior customer service experience.</p>';
        $data['dedicated_theme_sections'] = '[
            {
                "dedicated_theme_section_image":"",
                "dedicated_theme_section_heading":"Unified Ticket Management",
                "dedicated_theme_section_description":"<p>The Helpdesk Module consolidates customer inquiries into a unified ticketing system, enabling businesses to efficiently track, prioritize, and resolve tickets from a single platform. This centralized approach ensures that support agents can manage all inquiries effectively, enhancing responsiveness and preventing any issues from being overlooked. By streamlining ticket management, businesses can optimize their support workflows and deliver timely resolutions to customer queries.<\/p>",
                "dedicated_theme_section_cards":{"1":{"title":null,"description":null}}
            },
            {"dedicated_theme_section_image":"",
                "dedicated_theme_section_heading":"Streamlined Customer Support",
                "dedicated_theme_section_description":"<p>Revolutionizing customer support, the Helpdesk Module empowers businesses with a centralized platform to streamline support processes and improve response times. By providing a comprehensive toolset for managing customer inquiries, businesses can enhance their service delivery, boost customer satisfaction, and foster long-term loyalty. The module’s intuitive design ensures that support teams can handle inquiries efficiently, maintaining high standards of customer service across all interactions.<\/p>",
                "dedicated_theme_section_cards":{"1":{"title":null,"description":null}}
            },
            {"dedicated_theme_section_image":"",
                "dedicated_theme_section_heading":"Custom System Creation",
                "dedicated_theme_section_description":"<p>Super admins using the Helpdesk Module have the flexibility to create customized settings tailored to their business needs. This feature allows businesses to align the helpdesk environment with their brand identity and operational requirements, providing a personalized support experience. By configuring custom workflows and settings, businesses can enhance their professional image, improve internal efficiency, and ensure consistent service quality that meets the unique demands of their customer base.<\/p>",
                "dedicated_theme_section_cards":{"1":{"title":null,"description":null}}
            }
        ]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Helpdesk"},{"screenshots":"","screenshots_heading":"Helpdesk"},{"screenshots":"","screenshots_heading":"Helpdesk"},{"screenshots":"","screenshots_heading":"Helpdesk"},{"screenshots":"","screenshots_heading":"Helpdesk"}]';
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
