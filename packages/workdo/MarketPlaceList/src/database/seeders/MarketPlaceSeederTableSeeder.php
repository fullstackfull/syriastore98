<?php

namespace Workdo\MarketPlaceList\Database\Seeders;

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
        $module = 'MarketPlaceList';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'MarketPlaceList';
        $data['product_main_description'] = '<p>The MarketPlaceList Module in eCommerceGo SaaS showcases admin-created stores for easy access by customers.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = ' Marketplace Operating Hours with eCommerceGo SaaS.';
        $data['dedicated_theme_description'] = "<p>Running a successful marketplace requires efficient management of operating hours to ensure your customers know when you're open for business. With the MarketplaceList module in eCommerceGo SaaS, you have the tools to easily manage and display your marketplace's operating hours, helping you streamline operations and improve customer satisfaction.</p>";
        $data['dedicated_theme_sections'] = '[
                                                {
                                                    "dedicated_theme_section_image": "",
                                                    "dedicated_theme_section_heading": "Effortless Customization",
                                                    "dedicated_theme_section_description": "The MarketplaceList module allows you to customize the operating hours of your marketplace with ease. Whether you need to set different hours for weekdays and weekends or adjust your schedule for holidays, you can do it all from one convenient dashboard. Simply select the day of the week you want to modify, enter the start and end times, and save your changes. Its that simple. ",
                                                    "dedicated_theme_section_cards": {
                                                    "1": {
                                                        "title": "Enhanced Customer Experience",
                                                        "description": "By displaying your marketplaces operating hours on your website or app, you provide valuable information to your customers, helping them plan their visits or online purchases accordingly. With clear and accurate operating hours, you reduce the risk of customers showing up when youre closed, improving their overall experience and increasing the likelihood of repeat business ."
                                                    },
                                                    "2": {
                                                    "title": "Flexible Management Options",
                                                        "description": "The MarketplaceList module offers flexible management options to accommodate changes in your business schedule. Need to extend your hours during peak seasons or adjust your hours for special events? No problem! Simply update the operating hours as needed, and the changes will be reflected on the front end of your website or app, keeping your customers informed and engaged ."
                                                    },
                                                    "3": {
                                                    "title": "Streamlined Operations",
                                                        "description": "With eCommerceGo SaaS, managing your marketplaces operating hours is streamlined and efficient. Say goodbye to manual updates and confusion over business hours. With the MarketplaceList module, you can easily add, edit, and view operating hours for each day of the week, ensuring that your marketplace is always open when your customers need you ."
                                                    }
                                                    }
                                                }
                                            ]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"MarketPlaceList"},{"screenshots":"","screenshots_heading":"MarketPlaceList"},{"screenshots":"","screenshots_heading":"MarketPlaceList"},{"screenshots":"","screenshots_heading":"MarketPlaceList"},{"screenshots":"","screenshots_heading":"MarketPlaceList"}]';
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
