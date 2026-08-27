<?php

namespace Workdo\SMS\database\seeders;

use Illuminate\Database\Seeder;
use Workdo\SMS\app\Models\Notification;
use Workdo\SMS\app\Models\NotificationTemplateLang;

class NotificationsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            'SMS'       => ['Payment Complete', 'Change Status'],
        ];
        $defaultTemplate = [
            'Payment Complete' => [
                'variables' => '{"Amount" : "amount" , "User Name":"user_name" , "Payment Type" :"payment_type"}',
                'lang' => [
                    'ar' => 'دفعة جديدة {amount} تم تكوينه بواسطة {user_name} عبر {payment_type}',
                    'da' => 'En ny betaling af {amount} er oprettet af {user_name} via {payment_type}',
                    'de' => 'Eine neue Zahlung von {amount} wurde von {user_name} über {payment_type} erstellt.',
                    'en' => 'A new payment of {amount} has been created by {user_name} via {payment_type}',
                    'es' => 'Un nuevo pago de {amount} ha sido creado por {user_name} via {payment_type}',
                    'fr' => 'Un nouveau paiement de {amount} A été créé par {user_name} via {payment_type}',
                    'it' => 'Un nuovo pagamento di {importo} è stato creato da {user_name} via {payment_type}',
                    'ja' => '新規支払い {amount} によって作成された対象 {user_name} 経由 {payment_type}',
                    'nl' => 'Een nieuwe betaling van {amount} is gemaakt door {user_name} via {payment_type}',
                    'pl' => 'Nowa płatność {amount} został utworzony przez {user_name} za pośrednictwem {payment_type}',
                    'ru' => 'Новая выплата {amount} создано в {user_name} через {payment_type}',
                    'pt' => 'Um novo pagamento de {amount} foi criado por {user_name} via {payment_type}',
                    'zh' => '{user_name} 已通过 {payment_type} 创建了 { amount} 的新付款',
                    'he' => 'תשלום חדש של {מאונט} נוצר על-ידי {user_name} באמצעות {payment_type}',
                    'tr' => '{ user_name } tarafından { payment_type } aracılığıyla yeni bir { amount } ödemesi oluşturuldu</p>',
                    'pt-br' => 'Um novo pagamento de {amount} foi criado por {user_name} via {payment_type}',
                ],
            ],
            'Change Status' => [
                'variables' => '{"Status" : "status"}',
                'lang' => [
                    'ar' => 'الطلب الخاص بك هو { status }. شكرا لك لاختيار الخدمة الخاصة بنا !',
                    'da' => 'Din bestilling er { status }. Tak, fordi du har valgt vores service!',
                    'de' => 'Ihre Bestellung ist {status}. Vielen Dank für die Auswahl unseres Service!',
                    'en' => 'Your order is {status}. Thank you for choosing our service!',
                    'es' => 'Su pedido es {status}. ¡Gracias por elegir nuestro servicio!',
                    'fr' => 'Votre commande est { status }. Merci d`avoir choisi notre service !',
                    'it' => 'Il tuo ordine è {status}. Grazie per aver scelto il nostro servizio!',
                    'ja' => 'ご注文は {status}です。弊社のサービスをご選択いただき、ありがとうございます。',
                    'nl' => 'Uw bestelling is { status }. Dank u voor het kiezen van onze service!',
                    'pl' => 'Twoje zamówienie to {status }. Dziękujemy za wybranie naszej usługi!',
                    'ru' => 'Ваш заказ { status }. Спасибо, что выбрали нашу услугу!',
                    'pt' => 'Seu pedido é {status}. Obrigado por escolher o nosso serviço!',
                    'zh' => '您的订单为 {status} 。感谢您选择我们的服务 !',
                    'he' => 'ההזמנה שלכם היא {status} תודה שבחרתם בשירות שלנו!',
                    'tr' => 'Siparişiniz { status }. Hizmetimizi seçtiğiniz için teşekkür ederiz!',
                    'pt-br' => 'Seu pedido é {status}. Obrigado por escolher o nosso serviço!',
                ],
            ],
        ];

        foreach ($modules as $module_name => $actions) {
            foreach ($actions as $action) {
                $ntfy = Notification::where('action', $action)->where('module', 'sms')->count();
                if ($ntfy == 0) {
                    $new            = new Notification();
                    $new->action    = $action;
                    $new->module    = $module_name; 
                    $new->save();
                    foreach ($defaultTemplate[$action]['lang'] as $lang => $content) {
                        NotificationTemplateLang::create(
                            [
                                'parent_id' => $new->id,
                                'lang' => $lang,
                                'module' => $new->module,
                                'variables' => $defaultTemplate[$action]['variables'],
                                'content' => $content,
                            ]
                        );
                    }
                }
            }
        }
    }
}
