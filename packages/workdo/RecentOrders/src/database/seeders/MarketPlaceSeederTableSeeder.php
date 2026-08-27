<?php

namespace Workdo\RecentOrders\Database\Seeders;

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
        $module = 'RecentOrders';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Recent Orders';
        $data['product_main_description'] = '<p>Integrate our Recent Orders Module into your eCommerceGo SaaS platform to offer personalized order insights to your customers. Once enabled by the admin, this feature allows customers to view their own recent orders as they proceed with new purchases. By displaying a customers specific order history, you enhance their shopping experience and foster trust in your platform.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Real-Time Personal Order History';
        $data['dedicated_theme_description'] = '<p>With the Recent Orders Module enabled, customers can access their own recent order history in real-time. When placing a new order, they all see a list of their most recent purchases, providing valuable context and convenience. This personal touch ensures customers are always informed and empowered during their shopping journey.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Customizable Display Settings","dedicated_theme_section_description":"Admins have the flexibility to customize the number of recent orders displayed for each customer. For example, if an admin selects 5 orders, the customer will see their five most recent purchases when placing a new order. This customizable feature ensures a tailored experience for each individual, enhancing usability and satisfaction."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced User Engagement","dedicated_theme_section_description":"By offering personalized order insights, the Recent Orders Module enhances user engagement and satisfaction. Customers feel more connected to your platform when they can easily track their own order history, leading to increased loyalty and repeat business. This feature encourages customers to explore more products and complete their purchases with confidence."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"RecentOrders"},{"screenshots":"","screenshots_heading":"RecentOrders"},{"screenshots":"","screenshots_heading":"RecentOrders"},{"screenshots":"","screenshots_heading":"RecentOrders"},{"screenshots":"","screenshots_heading":"RecentOrders"}]';
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
