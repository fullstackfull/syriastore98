<?php

namespace Workdo\CartQuantityControl\database\seeders;

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
        $data['product_main_heading'] = 'CartQuantityControl';
        $data['product_main_description'] = '<p>In the competitive world of e-commerce, effective inventory management is crucial for success. The Cart Quantity module of eCommerce SaaS is designed to simplify this process, ensuring that your stock levels are accurately reflected in your online store. By automatically updating inventory as customers add items to their carts, this module helps prevent overselling and maintains the reliability of your stock information. Additionally, you can set the minimum and maximum quantity of products a customer can add to the cart at a time, providing greater control over your inventory.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Comprehensive Reporting and Analytics';
        $data['dedicated_theme_description'] = '<p>The Cart Quantity module also offers comprehensive reporting and analytics to help you make informed decisions about your inventory. Detailed reports on stock levels, sales trends, and cart activity provide valuable insights into customer behavior and product performance. With this data, you can optimize your inventory management strategies, improve your supply chain, and enhance overall operational efficiency.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Customer Experience","dedicated_theme_section_description":"Providing a seamless shopping experience is essential for customer satisfaction and retention. The Cart Quantity module improves the customer experience by clearly displaying available stock levels and preventing the addition of out-of-stock items to the cart. Customers can shop with confidence, knowing that the items they select are available for purchase. This transparency builds trust and encourages repeat business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Quantity Updates","dedicated_theme_section_description":"The Cart Quantity module offers real-time updates to your inventory, ensuring that customers always see the most current stock levels. As soon as an item is added to a cart, the available quantity is adjusted, providing an accurate reflection of what is in stock. This feature reduces the risk of customer frustration due to unavailable products and enhances the overall shopping experience. To utilize these features, it is necessary to enable the cart quantity control button from the store settings."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Dynamic Stock Alerts","dedicated_theme_section_description":"To further streamline inventory management, the Cart Quantity module includes dynamic stock alerts. When stock levels for a particular item reach a predefined threshold, notifications are automatically generated, prompting timely restocking. This proactive approach helps ensure that popular items are always available, preventing lost sales and maximizing revenue opportunities."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"CartQuantityControl"},{"screenshots":"","screenshots_heading":"CartQuantityControl"},{"screenshots":"","screenshots_heading":"CartQuantityControl"},{"screenshots":"","screenshots_heading":"CartQuantityControl"},{  "screenshots":"","screenshots_heading":"CartQuantityControl"},{  "screenshots":"","screenshots_heading":"CartQuantityControl"}]';
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
            if (!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'CartQuantityControl')->exists()) {
                MarketplacePageSetting::updateOrCreate(
                    [
                        'name' => $key,
                        'module' => 'CartQuantityControl',

                    ],
                    [
                        'value' => $value,
                    ]);
            }
        }
    }
}
