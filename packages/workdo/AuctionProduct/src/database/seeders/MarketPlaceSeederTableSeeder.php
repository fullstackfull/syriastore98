<?php

namespace Workdo\AuctionProduct\Database\Seeders;

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
        $module = 'AuctionProduct';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Auction Product';
        $data['product_main_description'] = '<p>Welcome to the Auction Configuration Module of eCommerceGo SaaS! This module makes setting up auctions a breeze. With easy-to-use features, you can configure auction settings quickly and efficiently. Specify the start and end dates, along with start and end times for your auction. Add the product name you want to auction off, and you are all set. Plus, you can edit these settings whenever needed, ensuring flexibility and control over your auction process. Remember, once you add a product name, it cannot be added again, so you can manage your auction items effectively.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Efficient Product Management';
        $data['dedicated_theme_description'] = '<p>In the Auction Products section, you have complete control over the products in your auction. View the list of products currently up for auction and make edits as required. Need to update product details or pricing? No problem. With just a few clicks, you can modify product information, ensuring that your auction listings are always accurate and up to date. This feature makes it easy to keep track of your inventory and optimize your auction offerings.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Bidding Monitoring","dedicated_theme_section_description":"Stay informed about bidding activity with the Auction Product Orders page. Here, you can view a comprehensive list of products on which customers have placed bids. Track bids in real-time and manage orders efficiently. With clear visibility into customer activity, you can make informed decisions about pricing and inventory management. The Auction Product Orders page gives you the insights you need to maximize your auction revenue and optimize your business strategy."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Automated Updates for Seamless Management","dedicated_theme_section_description":"Managing your auctions is a breeze with eCommerceGo SaaS. Take advantage of automated updates by adding corn job timing to the auction setting page. This ensures that your auction is corn job will keep updating at the specified time, without any manual intervention. By automating your auction updates, you can save time and streamline your workflow. Focus on growing your business while eCommerceGo takes care of the rest."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Start Maximizing Your Auction Profits Today","dedicated_theme_section_description":"With the Auction Configuration Module, you have everything you need to run successful auctions and maximize your profits. Whether you are a seasoned auctioneer or just getting started, eCommerceGo SaaS provides the tools and support you need to succeed. Configure your auctions effortlessly, manage your auction products with ease, and monitor bidding activity in real time. Plus, with automated updates, you can ensure that your auctions are always up to date. Get started with eCommerceGo SaaS and start maximizing your auction profits today!"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Auction Product"},{"screenshots":"","screenshots_heading":"Auction Product"},{"screenshots":"","screenshots_heading":"Auction Product"},{"screenshots":"","screenshots_heading":"Auction Product"},{  "screenshots":"","screenshots_heading":"Auction Product"}]';
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
