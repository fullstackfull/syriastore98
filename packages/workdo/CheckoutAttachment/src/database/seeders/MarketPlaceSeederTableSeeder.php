<?php

namespace Workdo\CheckoutAttachment\Database\Seeders;

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
        $module = 'CheckoutAttachment';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Checkout Attachment';
        $data['product_main_description'] = '<p>The Checkout Attachment Module for eCommerceGo introduces a streamlined way for customers to add attachments during the checkout process. This module empowers customers to include essential documents or files related to their orders, such as design specifications, proof of identity, or personalized instructions. By allowing attachments at checkout, the module enhances the clarity and accuracy of orders, ensuring that all necessary information is captured upfront.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Intuitive Attachment Process for Customers';
        $data['dedicated_theme_description'] = '<p>Designed with user experience in mind, the Checkout Attachment Module makes adding attachments a straightforward and hassle-free process. During checkout, customers are presented with an easy-to-use interface to upload their files. Supported file types and size limits can be configured to suit your business needs, ensuring compatibility and efficiency. This user-friendly approach helps reduce friction in the checkout process, leading to higher customer satisfaction and fewer order discrepancies.
        </p>';
        $data['dedicated_theme_sections'] = '[
            {
              "dedicated_theme_section_image": "",
              "dedicated_theme_section_heading": "Enhanced Communication with Administrators",
              "dedicated_theme_section_description": "Once a customer adds an attachment, it becomes immediately visible to the admin on the backend. This feature is particularly useful for businesses that require additional documentation to process orders accurately. Admins can easily access, review, and manage these attachments through the eCommerceGo admin panel. This seamless integration ensures that all relevant information is at the admin fingertips, facilitating smoother order processing and better customer service."
            },
            {
              "dedicated_theme_section_image": "",
              "dedicated_theme_section_heading": "Customer Transparency and Access",
              "dedicated_theme_section_description": "Transparency is a key benefit of the Checkout Attachment Module. Customers can view their uploaded attachments directly from the frontend, providing reassurance that their additional information has been successfully received and recorded. This visibility helps build trust and confidence in the purchasing process, as customers can verify that their special requests or required documents are in place. The ability to review attachments post-checkout also reduces the likelihood of follow-up queries, saving time for both customers and support teams."
            },
            {
              "dedicated_theme_section_image": "",
              "dedicated_theme_section_heading": "Optimizing Order Accuracy and Efficiency",
              "dedicated_theme_section_description": "By incorporating the Checkout Attachment Module, businesses can significantly enhance order accuracy and operational efficiency. The ability to attach files during checkout minimizes the risk of miscommunication and ensures that all specific requirements are clearly conveyed. This module is particularly beneficial for custom orders, services requiring documentation, or any scenario where additional information is critical. Ultimately, this feature contributes to a more organized and efficient workflow, leading to improved customer satisfaction and streamlined business operations."
            }
          ]
          ';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"CheckoutAttachment"},{"screenshots":"","screenshots_heading":"CheckoutAttachment"},{"screenshots":"","screenshots_heading":"CheckoutAttachment"},{"screenshots":"","screenshots_heading":"CheckoutAttachment"},{"screenshots":"","screenshots_heading":"CheckoutAttachment"}]';
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
