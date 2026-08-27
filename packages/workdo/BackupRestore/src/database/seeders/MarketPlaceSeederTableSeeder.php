<?php

namespace Workdo\BackupRestore\Database\Seeders;

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
        $module = 'BackupRestore';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'BackupRestore';
        $data['product_main_description'] = '<p>The primary purpose of backup is to prevent data loss. Regularly backing up data ensures that if unexpected events, such as hardware failures, human errors, or cyber-attacks, lead to data loss, you can restore your information from a previous point in time.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'What is Backup&Restore?';
        $data['dedicated_theme_description'] = '<p>Backup refers to the copying of physical or virtual files or databases to a secondary location for preservation in case of equipment failure or catastrophe.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"uploads\/marketplace_image\/BackupRestore\/1705555223-dedicated_theme_section_image.png","dedicated_theme_section_status":"on","dedicated_theme_section_heading":"Why Use Backup & Restore?","dedicated_theme_section_description":"<p>In the face of increasing cyber threats like ransomware, having regular backups provides a way to recover data without paying ransom if an attack occurs.<br><\/p>","dedicated_theme_section_cards":{"1":{"title":"Preventing Data Loss","description":"Backups serve as a safety net against data loss due to hardware failures, software issues, accidental deletion, malware attacks, or other unforeseen events. In the absence of backups, data could be permanently lost."},"2":{"title":"Protection Against Cyber Attacks","description":"Safeguards against ransomware, hacking attempts, and other cyber threats. Regular backups provide a means to restore the web application to a state before the attack occurred."},"3":{"title":"Database Recovery","description":"Protects against data loss in databases, ensuring that user accounts, product information, and other critical data can be restored in case of database corruption or accidental deletions."}}},{"dedicated_theme_section_image":"uploads\/marketplace_image\/BackupRestore\/1705555912-dedicated_theme_section_image.png","dedicated_theme_section_status":"on","dedicated_theme_section_heading":"Features Of Backup & Restore.","dedicated_theme_section_description":"<p>Backup and recovery is the process of duplicating data and storing it in a secure place in case of loss or damage<br><\/p>","dedicated_theme_section_cards":{"1":{"title":"Security","description":"Security remains an integral aspect of data backup and recovery. A company holding valuable information will face an increasing number of threats as IT systems continue growing and integrating with one another. The first thing you must do to save and protect your data is to maintain a backup and recovery solution."},"2":{"title":"Reliable Replication","description":"When you ensure accurate data replication, you ensure that your data is disaster-proof. Its essentially like a tape recorder, as when something bad happens, you can rewind it to meet your needs. All you need to do is rewind the tape, and the production will start from where the tape ended. That ensures you will not be losing out on any valuable data and information from your end."},"3":{"title":"Allows Management to Control Costs","description":"The best data backup and recovery software solutions help in reducing workforce overhead, which results in cost savings for the organization. It ensures that no matter what the problem is, you wont get to experience it because all your data will be safe and wont be compromised. That will allow you to maintain your functions and operations as needed."}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"uploads\/marketplace_image\/BackupRestore\/1705556157-screenshots.png","screenshots_heading":"BackupRestore"},{"screenshots":"uploads\/marketplace_image\/BackupRestore\/1705556173-screenshots.png","screenshots_heading":"BackupRestore"},{"screenshots":"uploads\/marketplace_image\/BackupRestore\/1705556179-screenshots.png","screenshots_heading":"BackupRestore"},{"screenshots":"uploads\/marketplace_image\/BackupRestore\/1705556186-screenshots.png","screenshots_heading":"BackupRestore"},{"screenshots":"uploads\/marketplace_image\/BackupRestore\/1705556196-screenshots.png","screenshots_heading":"BackupRestore"}]';
        $data['addon_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['whychoose_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['pricing_plan_heading'] = 'Empower Your Workforce with EcommerceGo';
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
