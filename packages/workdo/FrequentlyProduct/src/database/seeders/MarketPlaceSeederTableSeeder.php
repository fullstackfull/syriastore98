<?php

namespace Workdo\FrequentlyProduct\database\Seeders;

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

        // $this->call("OthersTableSeeder");

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'FrequentlyProduct';
        $data['product_main_description'] = '<p>Introducing the Frequently Product module of eCommerceGo SaaS – an innovative tool crafted to elevate your online store is performance and customer satisfaction. This powerful feature seamlessly integrates into your eCommerce platform, empowering you to effortlessly showcase complementary products that are frequently purchased together. By leveraging the purchasing behavior of your customers, this module strategically encourages shoppers to add more items to their carts, thereby boosting sales and enhancing the overall shopping experience.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Boost Sales with Complementary Product Showcasing';
        $data['dedicated_theme_description'] = '<p>With the Frequently Product module, you can increase cross-selling opportunities and drive revenue by providing personalized product recommendations tailored to each customer is browsing and purchasing history. By analyzing past transactions and customer preferences, the module intelligently suggests relevant products that complement the items in a shopper is cart, making it easy for them to discover and purchase additional items of interest.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Personalized Recommendations for Enhanced Shopping Experience","dedicated_theme_section_description":"But the customization does not stop there. With eCommerceGo is dynamic settings, end users have the flexibility to adjust the number of frequently bought together products displayed on their storefront. By fine-tuning this setting to suit their specific business needs and customer preferences, merchants can optimize their cross-selling strategies and maximize the impact of the \"Frequently Product\" module."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Customize Display Settings for Optimal Performance","dedicated_theme_section_description":"Moreover, in the frontend interface of eCommerceGo, users can seamlessly select the products they wish to feature in the column dedicated to frequently bought products. This intuitive feature allows merchants to curate their product recommendations based on factors such as popularity, seasonality, or strategic promotions, ensuring that the displayed products resonate with their target audience and drive conversions effectively."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Frontend Product Selection for Enhanced Merchandising","dedicated_theme_section_description":"<p>In essence, eCommerceGo is Frequently Bought Together module empowers merchants to harness the power of data-driven insights and personalized recommendations to drive sales and enhance customer satisfaction. Elevate your eCommerce experience and transform every transaction into a win-win for both you and your customers with this dynamic and versatile module.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"FrequentlyProduct"},{"screenshots":"","screenshots_heading":"FrequentlyProduct"},{"screenshots":"","screenshots_heading":"FrequentlyProduct"},{"screenshots":"","screenshots_heading":"FrequentlyProduct"},{"screenshots":"","screenshots_heading":"FrequentlyProduct"}]';
        $data['addon_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['whychoose_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['pricing_plan_heading'] = 'Empower Your Workforce with EcommerceGo';
        $data['pricing_plan_description'] = '<p>Access over Premium Add-ons for Product Enquiry, Product Review, Product Quick-view, and more, all in one place!</p>';
        $data['pricing_plan_demo_link'] = '#';
        $data['pricing_plan_demo_button_text'] = 'View Live Demo';
        $data['pricing_plan_text'] = '{"1":{"title":"Pay-as-you-go"},"2":{"title":"Unlimited installation"},"3":{"title":"Secure cloud storage"}}';
        $data['whychoose_sections_status'] = 'on';
        $data['dedicated_theme_section_status'] = 'on';

        foreach($data as $key => $value){
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'FrequentlyProduct')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'FrequentlyProduct'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
