<?php

namespace Workdo\ProductEnquiry\Database\Seeders;

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
        $module = 'ProductEnquiry';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Product Enquiry';
        $data['product_main_description'] = '<p>Allow your customers to easily inquire about products directly from your website. With the Product Enquiry feature, users can submit questions or request additional information about specific products with just a few clicks. Whether they are curious about product specifications, availability, or delivery options, this functionality enables seamless communication between shoppers and your support team. Customers can expect prompt responses, leading to increased trust and confidence in your brand.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Customization for Diverse Customer Needs';
        $data['dedicated_theme_description'] = '<p>Customization is key to meeting the diverse needs of your customer base, which is why Product Enquiry by eCommerceGo offers tailored Enquiry forms. Capture essential details from customers, such as their contact information and the specifics of their query, ensuring that your support team has all the necessary information to provide accurate and personalized responses. This personalized approach demonstrates your commitment to addressing customer needs promptly and comprehensively.
        </p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Prioritizing Timely Responses for Customer Satisfaction","dedicated_theme_section_description":"Timely responses are crucial in maintaining high levels of customer satisfaction and loyalty. With Product Enquiry by eCommerceGo, you can prioritize and address customer inquiries promptly, showcasing your dedication to exceptional customer service. By providing swift and helpful responses, you not only resolve customer queries efficiently but also strengthen their trust in your brand, leading to increased loyalty and repeat business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Streamlining Communication with Centralized Hub","dedicated_theme_section_description":"Centralized communication is key to efficient workflow processes and seamless customer support management. With Product Enquiry by eCommerceGo, all product inquiries are centralized within the platform, enabling your support team to track, monitor, and respond to customer messages with ease. This centralized hub streamlines communication channels, allowing for better organization and improved responsiveness to customer inquiries."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Unlocking Your eCommerce Potential with Product Enquiry by eCommerceGo","dedicated_theme_section_description":"<p>Unlock the full potential of your eCommerce business with Product Enquiry by eCommerceGo. By offering transparent and reliable support to your customers, you can enhance your brand reputation, drive sales, and foster long-term customer relationships. Empower your business with the tools needed to deliver exceptional service and stay ahead in todays competitive online landscape.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Product Enquiry"},{"screenshots":"","screenshots_heading":"Product Enquiry"},{"screenshots":"","screenshots_heading":"Product Enquiry"},{"screenshots":"","screenshots_heading":"Product Enquiry"},{"screenshots":"","screenshots_heading":"Product Enquiry"}]';
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
