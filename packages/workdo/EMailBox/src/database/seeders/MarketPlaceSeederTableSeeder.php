<?php

namespace Workdo\EMailBox\Database\Seeders;

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
        $module = 'EMailBox';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'EMail Box';
        $data['product_main_description'] = "<p>EMail Box module is a powerful tool designed to streamline your email management process. It seamlessly integrates with your SMTP server, giving you unprecedented control over your email account's various folders, including Inbox, Drafts, Spam, Sent, Trash, and Archive. But that's not all; it also empowers you to interact with your emails in ways that were previously unimaginable.</p>";
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2><b>Take Your Email </b> Experience to the Next Level with EMail Box</h2>';
        $data['dedicated_theme_description'] = "<p>Are you tired of the same old email routine? It's time to take your email experience to the next level with EMail Box! Our innovative module is designed to elevate your email management, making it easier and more efficient than ever before.</p>";
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Effortlessly Manage Your Email with EMail Box","dedicated_theme_section_description":"<p>In the EMail Box module, you gain seamless access to your email folders, including Inbox, Drafts, Spam, Sent, Trash, and Archive, all hosted on your SMTP server. This powerful tool empowers users to not only retrieve emails but also interact with them in real-time.<\/p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"EMail Box: Seamlessly Sync Your Email Actions","dedicated_theme_section_description":"<p>With EMail Box, every action you take—be it starring, marking as unread, or moving emails to different folders—syncs in real-time with your SMTP server email box. Experience unparalleled control and convenience as your email management becomes effortless and perfectly harmonized with your SMTP account.<\/p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"EMail Box"},{"screenshots":"","screenshots_heading":"EMail Box"},{"screenshots":"","screenshots_heading":"EMail Box"},{"screenshots":"","screenshots_heading":"EMail Box"},{"screenshots":"","screenshots_heading":"EMail Box"}]';
        $data['addon_heading'] = '<h2>Why choose dedicated modules<b> for Your Business?</b></h2>';
        $data['addon_description'] = '<p>With eCommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['whychoose_description'] = '<p>With eCommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['pricing_plan_heading'] = 'Empower Your Workforce with eCommerceGo';
        $data['pricing_plan_description'] = '<p>Access over Premium Add-ons for Accounting, HR, Payments, Leads, Communication, Management, and more, all in one place!</p>';
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
