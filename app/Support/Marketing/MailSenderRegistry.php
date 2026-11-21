<?php

namespace App\Support\Marketing;

/**
 * WHAT EACH KNOWN EMAIL SENDER IS FOR, written down with the evidence for it. D8 / GAP-200 / MKT-008.
 *
 * The consent gate needs a {@see MessageClass} for every message and there is deliberately no default, so something has to
 * say what each sender is. This is that list, and it is a list of DECISIONS, not guesses: an entry names a class only where
 * the code that sends it was read and shows who receives it and why. Everything else is `null` -- UNDECIDED -- and an
 * undecided sender is REPORTED, never defaulted, which is the whole point of the census beside it.
 *
 * TWO KINDS OF KEY, because the mail event carries two kinds of identity:
 *
 *   MAILABLES  -- the Mailable's class name, which `MessageSending::$data['__laravel_mailable']` carries for every Mailable, sync or
 *                 queued (measured on Laravel 11.57). `BY_TEMPLATE` marks the one class that is a CARRIER: `CommonEmailTemplate` is the
 *                 body of dozens of different messages, so its class says nothing and the TEMPLATE NAME decides.
 *   TEMPLATES  -- the name of the `email_templates` row a `CommonEmailTemplate` was built from. The names are platform-chosen labels
 *                 (the call sites pass a literal; `email_templates.name` is written only with literals). The BODY of a template is DATA,
 *                 so this classifies what the platform sends the template FOR, not what is written in it. Who may edit that body is
 *                 therefore a consent question too: until P1-SEC-64 any verified user could rewrite the platform-wide body of
 *                 'Order Created', and `EmailTemplateWriteOwnershipTest` now pins that only the template's owner can. The lookup here is
 *                 CASE-INSENSITIVE, like the `LIKE` that finds the row to send, so a stored 'abandon cart' cannot slip past an
 *                 'Abandon Cart' decision.
 *   NOTIFICATIONS -- a Notification class that mails, which `MessageSending::$data['__laravel_notification']` names.
 *
 * A SENDER NOT LISTED HERE IS UNDECIDED TOO. That is what makes `MailSenderRegistryCensusTest` possible: it fails when a Mailable class,
 * a Notification class, or a template name passed through any of the idioms this tree uses (`sendEmailTemplate('X'`,
 * `sendOwnerEmailTemplate('X'`, `EmailTemplate::where('name', 'X')`, `$emailTemplate = ['X']`) appears that is not a key below, and when
 * a `Mail::raw()` / `Mail::html()` / `Mail::send('view')` call appears at all (a raw message names no sender, so it is undecided by
 * construction). It CANNOT see a template name assembled at run time, and says so rather than implying otherwise: the runtime
 * report of undecided senders is the net under it.
 *
 * "REMIND" IS MARKETING in the owner's definition of the class ("promote, remind, win back or cross-sell"), so the cart, wishlist
 * and review reminders below are marketing. `Payment Reminder` is NOT decided here: an instalment-due notice is arguably a service
 * message, and whether the owner's word "remind" reaches it is the owner's call (design document, section 10).
 */
final class MailSenderRegistry
{
    /** The carrier class: its class name is not an answer, the template name is. */
    public const BY_TEMPLATE = 'by_template';

    /**
     * @var array<class-string|string, array{class: MessageClass|string|null, basis: string}>
     */
    public const MAILABLES = [
        'App\\Mail\\CommonEmailTemplate' => [
            'class' => self::BY_TEMPLATE,
            'basis' => 'the body of every Utility::sendEmailTemplate message and of seven vendored modules\' own copies of it; see TEMPLATES',
        ],
        'App\\Mail\\SendOTP' => [
            'class' => MessageClass::Transactional,
            'basis' => 'Api\\AuthController::518 mails a one-time code to the address that asked for it',
        ],
        'App\\Mail\\OTPVerify' => [
            'class' => MessageClass::Transactional,
            'basis' => 'its only call (Api\\AuthController::557) is commented out; the view is a one-time password for the account holder',
        ],
        'App\\Mail\\TestMail' => [
            'class' => MessageClass::Operational,
            'basis' => 'SettingController::712 and Api\\DashboardController::4742: the merchant tests their own SMTP settings, to an address they typed',
        ],
        'App\\Mail\\ProdcutMail' => [
            'class' => MessageClass::Transactional,
            'basis' => 'AccountProfileController::1146 mails the download link of a digital product to the billing address of the order that bought it',
        ],
        'App\\Mail\\ProdcutStockMail' => [
            'class' => null,
            'basis' => 'never constructed anywhere in the tree, so its purpose is a guess; a back-in-stock notice to a subscriber is not a campaign, a stock promotion is',
        ],
        'App\\Mail\\SendLoginDetail' => [
            'class' => null,
            'basis' => 'a stub: never constructed, and its view `view.name` does not exist',
        ],
        'App\\Mail\\invoicePay' => [
            'class' => null,
            'basis' => 'a stub: never constructed, and its view `view.name` does not exist',
        ],
        'App\\Mail\\ScheduledAnalyticsReport' => [
            'class' => MessageClass::Operational,
            'basis' => 'ANA-003. ScheduledReportRunner::send() mails a merchant their OWN store\'s analytics CSV, on a schedule they created themselves, to the address on their own user row -- re-read from that row on every run and deliberately not a parameter, so neither request input nor a hand-edited column can redirect it. No shopper is addressed and nothing is promoted, so no consent question arises; the nearest precedent in this table is TestMail',
        ],
        'Workdo\\CouponEmail\\app\\Emails\\CouponEmailTemplate' => [
            'class' => MessageClass::Marketing,
            'basis' => 'CouponEmail::sendCouponEmail mails a coupon on registration, on a first order and past an order-value threshold: a promotion (GAP-200). It carries NO store, so it cannot be attributed to one from the mail event',
        ],
        'Workdo\\EMailBox\\app\\Emails\\EmailsSendMail' => [
            'class' => null,
            'basis' => 'EmailBoxController.php:263-281: the MERCHANT types the recipients, subject and body; whether it promotes depends on what they wrote',
        ],
        'Workdo\\EMailBox\\app\\Emails\\ReplyMail' => [
            'class' => null,
            'basis' => 'merchant-composed correspondence; whether it promotes depends on what the merchant wrote',
        ],
        'Workdo\\PDFVouchers\\app\\Mail\\CouponStatusMail' => [
            'class' => null,
            'basis' => 'PDFVouchers MailSettingController.php:120-156: a merchant action mails a PDF of EVERY active coupon, with merchant-defined subject and content, to the address on a `coupon_requests` row. A requested coupon list, or a promotion? The owner\'s call',
        ],
    ];

    /**
     * @var array<string, array{class: MessageClass|null, basis: string}>
     */
    public const TEMPLATES = [
        'Order Created' => [
            'class' => MessageClass::Transactional,
            'basis' => 'PaymentService::3402: to the order\'s billing address, on order creation, as its confirmation',
        ],
        'Order Created For Owner' => [
            'class' => MessageClass::Operational,
            'basis' => 'PaymentService::3403: to the store owner\'s address, on order creation',
        ],
        'Status Change' => [
            'class' => MessageClass::Transactional,
            'basis' => 'OrderController::2032: to the order\'s delivery email, when its status changes',
        ],
        'Abandon Cart' => [
            'class' => MessageClass::Marketing,
            'basis' => 'CartController::1291: a reminder to a customer who left items in a cart ("remind" is marketing in the owner\'s definition)',
        ],
        'Abandon Wishlist' => [
            'class' => MessageClass::Marketing,
            'basis' => 'WishlistController::205: a reminder to a customer about their wishlist',
        ],
        'Review Reminder' => [
            'class' => MessageClass::Marketing,
            'basis' => 'ReviewReminder module: a reminder to review a purchase; listed among the known promotional senders in GAP-200',
        ],
        'Stock Status' => [
            'class' => null,
            'basis' => 'sent to the BUYER\'s billing address at order time (PaymentService.php:2849-2863, when stock is at or below the threshold and the product is set to "Allow, But notify customer"), which reads as a back-order notice. But the shipped body (Utility.php:702) says the product "is now back in stock. Do not miss this opportunity", so trigger and text contradict. Which one it is is the owner\'s question, and the body is merchant data',
        ],
        'Payment Reminder' => [
            'class' => null,
            'basis' => 'PartialPayments: an instalment-due notice. A service message, or a reminder in the owner\'s sense? The owner\'s call',
        ],
        'Product Catelog' => ['class' => null, 'basis' => 'ProductCatelog module; not read'],
        'Product Enquiry' => ['class' => null, 'basis' => 'ProductEnquiry module; whether it is to the merchant or a reply to the shopper was not read'],
        'Lucky Winner' => ['class' => null, 'basis' => 'SpinToWinReward / LotteryPrizes; a prize notice, not read'],
        'Lottery Win' => ['class' => null, 'basis' => 'LotteryPrizes; not read'],
        'Lottery Fail' => ['class' => null, 'basis' => 'LotteryPrizes; not read'],
        'Lottery Remainder' => [
            'class' => MessageClass::Operational,
            'basis' => 'LotteryPrizes Console/Lottery.php:71-80 mails the MERCHANT (`User::find($lottery_products->created_by)->email`) that a draw is due',
        ],
        'Pre Order Notification' => [
            'class' => MessageClass::Transactional,
            'basis' => 'PreOrder Console/PreOrder.php:87-114 mails the pre-order\'s own customer (`order_email`) their order id, status and link',
        ],
        'Pre Order Created' => [
            'class' => MessageClass::Transactional,
            'basis' => 'PreOrder PreOrderHistory.php:84: to the pre-order\'s own customer, on creation',
        ],
        'Pre Order Created For Owner' => [
            'class' => MessageClass::Operational,
            'basis' => 'PreOrder PreOrderHistory.php:85: to the store owner (`$owner->email`), on creation',
        ],
        'New Helpdesk Ticket' => ['class' => null, 'basis' => 'Helpdesk HelpdeskController.php:186: support correspondence on a ticket, sent through the module\'s own `EmailTemplate::sendEmailTemplate`, whose Mailable path was not traced'],
        'New Helpdesk Ticket Reply' => ['class' => null, 'basis' => 'Helpdesk HelpdeskConversionController.php:82-162: a reply on a ticket, to the requester or to staff depending on who wrote it; the Mailable path was not traced'],
    ];

    /**
     * @var array<class-string|string, array{class: MessageClass|null, basis: string}>
     */
    public const NOTIFICATIONS = [
        'App\\Notifications\\ResetPasswordNotification' => [
            'class' => MessageClass::Transactional,
            'basis' => 'Customer::sendPasswordResetNotification (Customer.php:279-283): a password-reset link to the account holder who asked for it',
        ],
        'App\\Notifications\\CustomResetPasswordNotification' => [
            'class' => MessageClass::Transactional,
            'basis' => 'the same reset link, built by the custom notification (Customer.php:264)',
        ],
    ];

    /** @return array{class: MessageClass|null, basis: string}|null the decision for a template name, matched the way the `LIKE` that finds the row matches it */
    public static function template(string $name): ?array
    {
        static $byLowerName = null;

        $byLowerName ??= array_combine(array_map('mb_strtolower', array_keys(self::TEMPLATES)), array_values(self::TEMPLATES));

        return $byLowerName[mb_strtolower(trim($name))] ?? null;
    }
}
