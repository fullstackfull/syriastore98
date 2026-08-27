<?php

namespace Workdo\Helpdesk\database\seeders;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class EmailTemplateDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();

        $emailTemplate = [
            'New Helpdesk Ticket',
            'New Helpdesk Ticket Reply',
        ];
        $defaultTemplate = [
            'New Helpdesk Ticket' =>
            [
                'subject' => 'New Helpdesk Ticket',
                'variables' => '{
                            "App Name": "app_name",
                            "App Url": "app_url",
                            "Ticket Name": "ticket_name",
                            "Email": "email",
                            "Ticket Id" : "ticket_id",
                            "Password": "password",
                            "Ticket Url": "ticket_url"
                      }',
                'lang' => [
                    'ar' => '<p>مرحبا بك<br />الى { app_name }</p>
                            <p><strong>البريد الالكتروني : { email } </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>كود بطاقة طلب الخدمة: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;">
                                <a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">انقر هنا لفتح التذكرة.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Thanks,<br />{app_name}</p>',
                    'da' => '<p>Velkommen<br />to {app_name}</p>
                            <p><strong>E-mail : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>Ticket ID: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Klik her for at åbne billetten.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Tak,<br />{app_name}</p>',
                    'de' => '<p>Begrüßung<br />to {app_name}</p>
                            <p><strong>Email : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>Ticket ID: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Klicken Sie hier, um das Ticket zu öffnen.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Vielen Dank,<br />{app_name}</p>',
                    'en' => '<p>Welcome<br />to {app_name}</p>
                            <p><strong>Email : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>Ticket ID: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;"> Click here to open the ticket </strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Thanks,<br />{app_name}</p>',
                    'es' => '<p>Bienvenido<br />to {app_name}</p>
                            <p><strong>Correo electrónico : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>ID de ticket: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Haga clic aquí para abrir el ticket.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Gracias,<br />{app_name}</p>',
                    'fr' => '<p>Bienvenue<br />to {app_name}</p>
                            <p><strong>Courrier électronique : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>ID de ticket: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Cliquez ici pour ouvrir le ticket.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Merci,<br />{app_name}</p>',
                    'it' => '<p>Benvenuto<br />to {app_name}</p>
                            <p><strong>Email : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>ID ticket: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Clicca qui per aprire il ticket.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Grazie,<br />{app_name}</p>',
                    'ja' => '<p>ようこそ<br />to {app_name}</p>
                            <p><strong>E メール: {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>チケット ID: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">ここをクリックしてチケットを開きます。</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>ありがと,<br />{app_name}</p>',
                    'nl' => '<p>Welkom<br />to {app_name}</p>
                            <p><strong>E-mail : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>Ticket-ID: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Klik hier om het kaartje te openen.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Bedankt,<br />{app_name}</p>',
                    'pl' => '<p>Powitanie<br />to {app_name}</p>
                            <p><strong>Adres e-mail : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>Id. zgłoszenia: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Kliknij tutaj, aby otworzyć bilet.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Dziękujemy,<br />{app_name}</p>',
                    'ru' => '<p>Приветствие<br />to {app_name}</p>
                            <p><strong>Электронная почта : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>ID паспорта: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Нажмите здесь, чтобы открыть билет.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Спасибо,<br />{app_name}</p>',
                    'pt' => '<p>Bem-vindo<br />to {app_name}</p>
                            <p><strong>E-mail : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>ID do chamado: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Clique aqui para abrir o ticket.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Obrigado,<br />{app_name}</p>',
                    'tr' => '<p>Hoş Geldiniz<br />to {app_name}</p>
                            <p><strong>Eposta : {email} </strong><strong><br /><strong><strong>
                            <p><strong><strong><strong> </strong>Bildirim Formu Tanıtıcısı: {ticket_id}<br />
                                <p style="text-align: center;" align="center"><span style="font-size: 18pt;"><a style="background: #6676ef; color: #ffffff; font-family: "Open Sans", Helvetica, Arial, sans-serif; font-weight: normal; line-height: 120%; margin: 0px; text-decoration: none; text-transform: none;" href="{ticket_url}" target="_blank" rel="noopener"> <strong style="color: white; font-weight: bold; text: white;">Bileti açmak için buraya tıklayın.</strong> </a></span></p>
                                    </strong> </a></span></span></p>
                            <p>{app_url}</p>
                            <p>Teşekkürler,<br />{app_name}</p>',
                ],
            ],
            'New Helpdesk Ticket Reply' => [
                'subject' => 'Helpdesk Ticket Reply',
                'variables' => '{
                        "App Name" : "app_name",
                        "Company Name" : "company_name",
                        "App Url": "app_url",
                        "Ticket Name" : "ticket_name",
                        "Ticket Id" : "ticket_id",
                        "Ticket Description" : "reply_description"
                    }',
                'lang' => [
                    'ar' => '<p>مرحبا ، مرحبا بك في { app_name }.</p><p>&nbsp;</p><p>{ ticket_name }</p><p>{ ticket_id }</p><p>&nbsp;</p><p>الوصف : { reply_description }</p><p>&nbsp;</p><p>شكرا</p><p>{ app_name }</p>',
                    'da' => '<p>Hej, velkommen til { app_name }.</p><p>&nbsp;</p><p>{ ticket_name }</p><p>{ ticket_id }</p><p>&nbsp;</p><p>Beskrivelse: { reply_description }</p><p>&nbsp;</p><p>Tak.</p><p>{ app_name }</p>',
                    'de' => '<p>Hallo, Willkommen bei {app_name}.</p><p>&nbsp;</p><p>{ticketname}</p><p>{ticket_id}</p><p>&nbsp;</p><p>Beschreibung: {reply_description}</p><p>&nbsp;</p><p>Danke,</p><p>{Anwendungsname}</p>',
                    'en' => '<p>Hello,&nbsp;<br />Welcome to {app_name}.</p><p>{ticket_name}</p><p>{ticket_id}</p><p><strong>Description</strong> : {reply_description}</p><p>Thanks,<br />{app_name}</p>',
                    'es' => '<p>Hola, Bienvenido a {app_name}.</p><p>&nbsp;</p><p>{ticket_name}</p><p>{ticket_id}</p><p>&nbsp;</p><p>Descripci&oacute;n: {reply_description}</p><p>&nbsp;</p><p>Gracias,</p><p>{app_name}</p>',
                    'fr' => '<p>Hola, Bienvenido a {app_name}.</p><p>&nbsp;</p><p>{ticket_name}</p><p>{ticket_id}</p><p>&nbsp;</p><p>Descripci&oacute;n: {reply_description}</p><p>&nbsp;</p><p>Gracias,</p><p>{app_name}</p>',
                    'it' => '<p>Ciao, Benvenuti in {app_name}.</p><p>&nbsp;</p><p>{ticket_name}</p><p>{ticket_id}</p><p>&nbsp;</p><p>Descrizione: {reply_description}</p><p>&nbsp;</p><p>Grazie,</p><p>{app_name}</p>',
                    'ja' => '<p>こんにちは、 {app_name}へようこそ。</p><p>&nbsp;</p><p>{ticket_name}</p><p>{ticket_id}</p><p>&nbsp;</p><p>説明 : {reply_description}</p><p>&nbsp;</p><p>ありがとう。</p><p>{app_name}</p>',
                    'nl' => '<p>Hallo, Welkom bij { app_name }.</p><p>&nbsp;</p><p>{ ticket_name }</p><p>{ ticket_id }</p><p>&nbsp;</p><p>Beschrijving: { reply_description }</p><p>&nbsp;</p><p>Bedankt.</p><p>{ app_name }</p>',
                    'pl' => '<p>Witaj, Witamy w aplikacji {app_name }.</p><p>&nbsp;</p><p>{ticket_name }</p><p>{ticket_id }</p><p>&nbsp;</p><p>Opis: {reply_description }</p><p>&nbsp;</p><p>Dziękuję,</p><p>{app_name }</p>',
                    'ru' => '<p>Здравствуйте, Добро пожаловать в { app_name }.</p><p>&nbsp;</p><p>Witaj, Witamy w aplikacji {app_name }.</p><p>&nbsp;</p><p>{ticket_name }</p><p>{ticket_id }</p><p>&nbsp;</p><p>Opis: {reply_description }</p><p>&nbsp;</p><p>Dziękuję,</p><p>{app_name }</p>',
                    'pt' => '<p>Ol&aacute;, Bem-vindo a {app_name}.</p><p>&nbsp;</p><p>{ticket_name}</p><p>{ticket_id}</p><p>&nbsp;</p><p>Descri&ccedil;&atilde;o: {reply_description}</p><p>&nbsp;</p><p>Obrigado,</p><p>{app_name}</p>',
                    'tr' => '<p>Merhaba, &nbsp;<br />{ app_name } olanağına hoş geldiniz.</p><p>{ ticket_name }</p><p>{ ticket_id }</p><p><strong>Açıklama</strong> : { reply_description }</p><p>Teşekkürler,<br />{ uyg_adı }</p>',
                ],
            ],
        ];

        foreach ($emailTemplate as $eTemp) {
            $table = EmailTemplate::where('name', $eTemp)->where('module_name', 'Helpdesk')->exists();
            if (!$table) {
                $emailtemplate =  EmailTemplate::create(
                    [
                        'name' => $eTemp,
                        'from' => 'BookingGo',
                        'module_name' => 'Helpdesk',
                        'created_by' => 1,
                        'business_id' => 0
                    ]
                );
                foreach ($defaultTemplate[$eTemp]['lang'] as $lang => $content) {
                    EmailTemplateLang::create(
                        [
                            'parent_id' => $emailtemplate->id,
                            'lang' => $lang,
                            'subject' => $defaultTemplate[$eTemp]['subject'],
                            'variables' => $defaultTemplate[$eTemp]['variables'],
                            'content' => $content,
                        ]
                    );
                }
            }
        }
    }
}
