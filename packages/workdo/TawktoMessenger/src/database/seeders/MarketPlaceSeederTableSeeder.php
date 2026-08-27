<?php

namespace Workdo\TawktoMessenger\database\seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Workdo\LandingPage\Entities\MarketplacePageSetting;

class MarketPlaceSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        Model::unguard();
        $module = 'TawktoMessenger';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'TawktoMessenger';
        $data['product_main_description'] = '<p>TawkToMessenger is a powerful tool that enables live chat and messaging features on your website, allowing you to engage with your customers in real-time.Go to the TawkTo website and login with your credentials. Once logged in, you`ll land on the Dashboard. If not, click on the skip button of all the pages until you reach the Dashboard.On the Dashboard, navigate to the settings.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2>Integrating <b>TawkToMessenger Module</b> with eCommerceGo Saas</h2>';
        $data['dedicated_theme_description'] = '<p>Integrating TawkToMessenger with eCommerceGo Saas is a seamless process that enhances your customer support and interaction capabilities.Follow these simple steps to integrate TawkToMessenger into your eCommerceGo Saas platform.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Steps of integrate TawkToMessenger","dedicated_theme_section_description":"<span>1. Login to TawkTo</span><p>->Go to the TawkTo website and login with your credentials.</p>2. Navigate to Dashboard</span><p>->Once logged in, you`ll land on the Dashboard. If not, click on the skip button of all the pages until you reach the Dashboard.</p><span>3. Access Settings</span><p>->On the Dashboard, navigate to the settings.</p><span>4. Configure Channel Settings</span><p>->On the Dashboard, navigate to the settings.</p><span>5. Customize Chat Widget</span><p>->Under Channels, locate the chat widget settings.</p><span>6. Obtain Widget Code</span><p>->You will find the widget code in this section. Copy the code.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Integrate with eCommerceGo Saas","dedicated_theme_section_description":"<span>1. Integrate with eCommerceGo Saas</span><p>->Now, head over to the system settings of eCommerceGo Saas.</p><span>2. Access TawkTo Settings</span><p>->In the system settings, find the TawkTo settings</p><span>3. Insert Widget Code</span><p>->Paste the copied widget code into the designated field.</p><span>4. Save Changes</span><p>->Save the changes, and you`re done! The TawkToMessenger module is now successfully integrated with your eCommerceGo Saas platform..</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Benefits of TawkToMessenger Integration","dedicated_theme_section_description":"<span><b>Real-Time Customer Support:</b>Go to the TawkTo website and login with your credentials.</span><br><span><b>Increased Customer Interaction:</b>Foster better communication with your customers, leading to improved satisfaction and loyalty.</span><br><span><b>Enhanced User Experience:</b>TawkToMessenger adds a personalized touch to your website, making it more user-friendly and interactive.</span><br><span><b>Efficient Issue Resolution:</b>Resolve customer queries and issues promptly, leading to higher customer retention rates.</span><br><span><b>Analytics and Insights:</b>Utilize TawkToMessenger`s analytics to gain insights into customer behavior and preferences.</span><br><br><p>Integrating TawkToMessenger with eCommerceGo Saas empowers you to deliver exceptional customer service, driving business growth and success. Start engaging with your customers in real-time and take your online presence to the next level!</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"TawktoMessenger"},{"screenshots":"","screenshots_heading":"TawktoMessenger"},{"screenshots":"","screenshots_heading":"TawktoMessenger"},{"screenshots":"","screenshots_heading":"TawktoMessenger"},{"screenshots":"","screenshots_heading":"TawktoMessenger"}]';
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
