<?php

namespace Workdo\PartialPayments\Database\Seeders;

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
        $module = 'PartialPayments';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Partial Payments';
        $data['product_main_description'] = '<p>The Partial Payment Module in eCommerceGo SAAS enables you to provide your customers with the flexibility of paying for their purchases in manageable installments. This feature is particularly beneficial for high-ticket items, making your products more accessible to a broader audience. By offering installment plans, you not only enhance customer satisfaction but also increase your sales potential. This flexibility allows customers to make larger purchases without the immediate burden of paying the full amount upfront, fostering a more positive buying experience.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Control Over Deposits';
        $data['dedicated_theme_description'] = '<p>Customize the payment experience by enabling or disabling the force deposit button according to your business requirements. This feature allows you to decide whether an initial deposit is necessary for your customers to begin the installment plan. Having control over this setting ensures that you can adapt the payment process to suit different scenarios and customer preferences. This flexibility helps you maintain a balance between securing a commitment from the customer and providing a convenient payment option.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Comprehensive Payment History","dedicated_theme_section_description":"The Partial Payment Module offers a comprehensive view of all partial payments made by your customers. You can effortlessly access detailed payment histories, including dates, amounts, and payment methods. This transparency helps you maintain accurate records, making it easier to manage finances and address any customer inquiries regarding their payment schedules. By having a clear and organized history of payments, you can ensure better financial management and customer service."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Payment Status","dedicated_theme_section_description":"Stay informed about the status of each installment with real-time payment tracking. The module clearly indicates whether a payment has been made or is still pending. This feature helps you keep track of outstanding balances, ensuring that you can follow up with customers promptly and manage your cash flow more effectively. Knowing the payment status at a glance allows you to make informed decisions and take necessary actions to ensure timely payments."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"PartialPayments"},{"screenshots":"","screenshots_heading":"PartialPayments"},{"screenshots":"","screenshots_heading":"PartialPayments"},{"screenshots":"","screenshots_heading":"PartialPayments"},{"screenshots":"","screenshots_heading":"PartialPayments"}]';
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
