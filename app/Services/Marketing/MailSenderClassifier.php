<?php

namespace App\Services\Marketing;

use App\Models\EmailTemplate;
use App\Support\Marketing\MailSender;
use App\Support\Marketing\MailSenderRegistry;
use App\Support\Marketing\MessageClass;
use Illuminate\Mail\Events\MessageSending;

/**
 * WHAT IS THIS EMAIL FOR? Reads the mail event and the registry. D8 / GAP-200 / MKT-008.
 *
 * Everything it needs is in `MessageSending::$data`, measured on Laravel 11.57: `__laravel_mailable` (the Mailable's class name) for any
 * Mailable, sync or queued; `__laravel_notification` for notification mail; neither for a `Mail::raw()` / `Mail::send('view')`.
 * No backtrace is needed to name the sender, and no vendored class needs a marker.
 *
 * `CommonEmailTemplate` is only a CARRIER: its class says nothing and the template it was built from decides. The template is an
 * `EmailTemplateLang` row whose `parent_id` is the `email_templates` row, so its NAME costs one (model-cached) read, made only when
 * the gate is not off.
 *
 * THE STORE comes from the event only where the Mailable carries it (`CommonEmailTemplate::$store`). It is NOT taken from the request or
 * from `getCurrentStore()`: a queued send has no request, and a merchant browsing another store's storefront resolves THEIR store.
 */
class MailSenderClassifier
{
    public function classify(MessageSending $event): MailSender
    {
        $data = $event->data;

        if (is_string($mailable = $data['__laravel_mailable'] ?? null) && $mailable !== '') {
            return $this->mailable($mailable, $data);
        }

        if (is_string($notification = $data['__laravel_notification'] ?? null) && $notification !== '') {
            $entry = MailSenderRegistry::NOTIFICATIONS[$notification] ?? null;

            return new MailSender(MailSender::KIND_NOTIFICATION, self::loggable($notification), null, $entry['class'] ?? null, null);
        }

        return new MailSender(MailSender::KIND_RAW, 'raw', null, null, null);
    }

    /** @param  array<string, mixed>  $data */
    private function mailable(string $class, array $data): MailSender
    {
        $entry = MailSenderRegistry::MAILABLES[$class] ?? null;
        $storeId = self::storeId($data['store'] ?? null);

        if ($entry === null) {
            // An anonymous class's name embeds a NUL byte and a file path; neither belongs in a log line.
            return new MailSender(MailSender::KIND_MAILABLE, self::loggable($class), null, null, $storeId);
        }

        if ($entry['class'] === MailSenderRegistry::BY_TEMPLATE) {
            $name = $this->templateName($data['template'] ?? null);
            $templated = $name === null ? null : (MailSenderRegistry::template($name)['class'] ?? null);

            return new MailSender(MailSender::KIND_MAILABLE, $class, $name ?? '(unknown template)', $templated, $storeId);
        }

        return new MailSender(MailSender::KIND_MAILABLE, $class, null, $entry['class'] instanceof MessageClass ? $entry['class'] : null, $storeId);
    }

    /** The `email_templates.name` a `CommonEmailTemplate` was built from, or null when the carrier holds something this cannot read. */
    private function templateName(mixed $template): ?string
    {
        if (! is_object($template) || ! isset($template->parent_id) || ! is_numeric($template->parent_id)) {
            return null;
        }

        $name = EmailTemplate::query()->whereKey((int) $template->parent_id)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** An anonymous class's name embeds a NUL byte and a file path; neither belongs in a log line. */
    private static function loggable(string $class): string
    {
        return str_contains($class, '@anonymous') ? 'anonymous '.(str_contains($class, 'Notification') ? 'Notification' : 'Mailable') : $class;
    }

    private static function storeId(mixed $store): ?int
    {
        $id = match (true) {
            is_object($store) => $store->id ?? null,
            is_array($store) => $store['id'] ?? null,
            default => null,
        };

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}
