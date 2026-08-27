<?php

namespace Workdo\ProductCSV\Database\Seeders;

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
        $module = 'ProductCSV';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'ProductCSV';
        $data['product_main_description'] = '<p>The CSV Module in eCommerceGo SaaS offers a seamless solution for managing and exporting your data in CSV format. With just a click of a button, administrators can enable the CSV functionality, allowing customers to download CSV files directly from the front end of the website or app. This powerful feature streamlines data management processes, enhances user experience, and provides valuable insights into your business data.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2>Effortless Data Export</h2>';
        $data['dedicated_theme_description'] = '<p>With the CSV Module, exporting data from your eCommerce platform becomes effortless. Whether you need to export product catalogs, customer information, or sales data, simply enable the CSV button, and customers can download the data in CSV format with ease. This eliminates the need for manual data entry or complex export processes, saving time and reducing the risk of errors.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Customizable Export Options","dedicated_theme_section_description":"<p>The CSV Module offers customizable export options to meet your specific business needs. Administrators can choose which data fields to include in the CSV file, allowing for tailored exports that focus on the most relevant information. Whether you need to export basic product details or comprehensive sales reports, you have full control over the exported data.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Data Accessibility","dedicated_theme_section_description":"<p>Tailor your exploration experience to suit your preferences. With customizable viewing options, you can focus on the details that matter most to you. Whether you are interested in specific product features, pricing tiers, or availability status, our module allows you to adjust the viewing settings to align with your priorities, ensuring that you can explore products at your own pace.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Insightful Data Analysis","dedicated_theme_section_description":"<p>The CSV Module facilitates insightful data analysis by providing easy access to raw data in CSV format. Customers can import the CSV files into their preferred spreadsheet software, such as Excel or Google Sheets, and perform in-depth analysis, generate custom reports, or visualize data trends. This enables data-driven decision-making and helps businesses uncover valuable insights to drive growth and optimization.    </p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ProductCSV"},{"screenshots":"","screenshots_heading":"ProductCSV"},{"screenshots":"","screenshots_heading":"ProductCSV"},{"screenshots":"","screenshots_heading":"ProductCSV"},{"screenshots":"","screenshots_heading":"ProductCSV"}]';
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
