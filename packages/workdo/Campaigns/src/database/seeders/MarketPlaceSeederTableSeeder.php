<?php

namespace Workdo\Campaigns\database\seeders;

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
        $data['product_main_heading'] = 'Campaigns';
        $data['product_main_description'] = '<p>The Campaign Module allows you to design and launch marketing campaigns without hassle. With its user-friendly interface, you can quickly set up campaign details, including target audience, campaign objectives, and promotional materials. This streamlined process ensures that you can focus on crafting compelling messages and visuals to captivate your audience, rather than getting bogged down in technical details.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Versatile Promotion Tools';
        $data['dedicated_theme_description'] = '<p>Promoting your campaigns is straightforward with the Campaign Modules versatile promotion tools. Utilize email marketing, social media integrations, and targeted ads to reach your desired audience. You can tailor your promotional efforts to different channels, ensuring that your message resonates with various segments of your customer base. By leveraging these tools, you can maximize your campaigns reach and impact.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Organized Campaign Management","dedicated_theme_section_description":"Managing multiple campaigns can be challenging, but the Campaign Module keeps everything organized. The module provides a centralized dashboard where you can monitor the progress of all your campaigns, manage tasks, and track milestones. This organized approach helps you stay on top of deadlines, coordinate team efforts, and ensure that each campaign runs smoothly from start to finish."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Performance Tracking","dedicated_theme_section_description":"After your campaign concludes, the Campaign Module provides tools to review its performance. Analyze key metrics such as engagement rates, conversion rates, and customer feedback to assess the success of your efforts. Use these insights to identify areas for improvement and optimize future campaigns. By continuously refining your strategies, you can achieve better results and drive your business growth."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Effective Campaign Marketing Tactics","dedicated_theme_section_description":"Unlock the full potential of your marketing campaigns with the Campaign Module, designed to showcase effective campaign tactics. This module offers a suite of features that help you craft and implement impactful marketing strategies. From targeting specific audience segments to optimizing promotional offers, you will find all the tools you need to enhance your campaign reach and effectiveness."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Campaigns"},{"screenshots":"","screenshots_heading":"Campaigns"},{"screenshots":"","screenshots_heading":"Campaigns"},{"screenshots":"","screenshots_heading":"Campaigns"},{"screenshots":"","screenshots_heading":"Campaigns"},{"screenshots":"","screenshots_heading":"Campaigns"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'Campaigns')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'Campaigns'
                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
