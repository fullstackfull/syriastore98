<?php

namespace Workdo\ProductCompare\Database\Seeders;

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
        $module = 'ProductCompare';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Product Compare';
        $data['product_main_description'] = '<p>Introducing the Product Compare module by eCommerceGo SaaS, designed to simplify your product evaluation journey. We understand that making informed purchasing decisions is crucial. That is why we have developed an intuitive feature that allows you to effortlessly explore product details, enabling you to assess their features, specifications, and prices conveniently.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Seamless Evaluation Process';
        $data['dedicated_theme_description'] = '<p>With Product Compare, exploring products is a breeze. Our user-friendly interface lets you view detailed product information in a structured and organized manner. Whether you are researching electronics, clothing, or home goods, our module simplifies the evaluation process by presenting relevant details in an easy-to-navigate format.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Comprehensive Product Details","dedicated_theme_section_description":"Make well-informed decisions with our comprehensive product details. From technical specifications to product descriptions and pricing information, our module provides you with all the essential information you need to evaluate products effectively. With just a few clicks, you can explore product features, availability, and pricing, empowering you to make informed choices that align with your needs."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Personalized Exploration Experience","dedicated_theme_section_description":"Tailor your exploration experience to suit your preferences. With customizable viewing options, you can focus on the details that matter most to you. Whether you are interested in specific product features, pricing tiers, or availability status, our module allows you to adjust the viewing settings to align with your priorities, ensuring that you can explore products at your own pace."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Product Compare"},{"screenshots":"","screenshots_heading":"Product Compare"},{"screenshots":"","screenshots_heading":"Product Compare"},{"screenshots":"","screenshots_heading":"Product Compare"},{"screenshots":"","screenshots_heading":"Product Compare"}]';
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
