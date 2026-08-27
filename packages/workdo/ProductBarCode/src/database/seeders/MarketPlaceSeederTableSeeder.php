<?php

namespace Workdo\ProductBarCode\Database\Seeders;

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
        $module = 'ProductBarCode';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Product BarCode';
        $data['product_main_description'] = '<p>Introducing the Product Barcode Module of eCommerceGo SaaS, an essential tool for modern businesses looking to optimize their inventory management processes. With this module, businesses can effortlessly generate and scan product barcodes, streamlining inventory control and ensuring accurate tracking of stock levels. Seamlessly integrated into the eCommerce platform, the barcode module offers convenience and efficiency, allowing for smooth communication between inventory management and sales channels.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'EffoSimplifying Inventory Management with Unique Barcodes';
        $data['dedicated_theme_description'] = '<p>One of the key features of the Product Barcode Module is its ability to generate unique barcodes for each product in the inventory. This simplifies the identification and tracking of products, reducing errors and enhancing overall efficiency. Additionally, the module supports mobile scanning, enabling users to scan barcodes directly from their smartphones or tablets. This real-time scanning capability facilitates instant updates to inventory records, ensuring that businesses always have accurate information about their stock levels. You can also select the types and color of the barcode, that will be visible on the front end of your theme.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhancing Efficiency with Mobile Scanning Capabilities","dedicated_theme_section_description":"Efficient inventory control is crucial for any business, and the Product Barcode Module helps achieve this by providing comprehensive inventory management functionalities. Businesses can easily track product movement, monitor stock levels, and prevent stockouts by leveraging the module is capabilities. By maintaining accurate inventory records, businesses can minimize operational disruptions and ensure a seamless customer experience."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Comprehensive Inventory Control Functionality","dedicated_theme_section_description":"Furthermore, the integration of the barcode module into the eCommerce platform enhances overall workflow efficiency. With seamless communication between inventory management and sales channels, businesses can effectively synchronize their operations and optimize their supply chain processes. This integration also enables businesses to respond quickly to changes in demand and make informed decisions about inventory replenishment and fulfillment."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Streamlined Workflow Efficiency through Integration","dedicated_theme_section_description":"<p>In summary, the Product Barcode Module of eCommerceGo SaaS offers businesses a powerful solution for optimizing inventory management. By simplifying barcode generation and scanning, supporting mobile capabilities, and facilitating seamless integration with the eCommerce platform, the module empowers businesses to enhance efficiency, minimize errors, and improve overall operational performance.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ProductBarCode"},{"screenshots":"","screenshots_heading":"ProductBarCode"},{"screenshots":"","screenshots_heading":"ProductBarCode"},{"screenshots":"","screenshots_heading":"ProductBarCode"},{"screenshots":"","screenshots_heading":"ProductBarCode"}]';
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
