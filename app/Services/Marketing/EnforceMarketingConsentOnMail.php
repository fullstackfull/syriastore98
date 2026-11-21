<?php

namespace App\Services\Marketing;

use App\Support\Marketing\Channel;
use App\Support\Marketing\MailSender;
use App\Support\Marketing\MessageClass;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * THE EMAIL SEAM OF THE CONSENT GATE. D8 / GAP-200 / MKT-008.
 *
 * IT LIVES IN `app/Services/Marketing/`, NOT `app/Listeners/`, ON PURPOSE. The framework's own EventServiceProvider has event discovery
 * ON over `app/Listeners` (see the note in AppServiceProvider on `NormaliseRewardRedemptionFlag`), so a listener placed there is
 * registered by its `handle()` signature AND by an explicit entry, and runs twice per message. Here it is registered exactly once, by
 * the explicit entry in App\Providers\EventServiceProvider, and a test counts.
 *
 * Laravel's `MessageSending` listener sits in front of the only `transport->send` in `Illuminate\Mail`, and returning `false` aborts
 * the send -- measured, for vendored-module Mailables too. So this is where an email that needs consent is held, whichever of the
 * product's many senders built it. It is the ONLY place the gate is wired, and it is INERT unless `MARKETING_CONSENT_ENFORCEMENT` is
 * `report` or `enforce`.
 *
 * WHAT IT DOES, in order:
 *
 *   1. mode `off` (the default) -> return at once. No classification, no query, no backtrace: a configuration read and a comparison on
 *      the path of every email the product sends.
 *   2. classify the sender ({@see MailSenderClassifier}). An UNDECIDED sender -- one the registry does not classify -- is REPORTED, once
 *      per sender per process, with the place it was sent from (never an address), and is NOT held, in any mode. Holding a message
 *      nobody can identify would stop a one-time password or a receipt, which the owner's direction forbids; letting it through is
 *      the cost of not knowing, and the reported list is how that cost is driven to zero. Whether `enforce` should hold an undecided
 *      sender instead is the owner's call (design document, section 10).
 *   3. a sender that is not marketing -> return.
 *   4. marketing: ask the gate, per RECIPIENT, with the store the message can be attributed to. In `report` the gate logs what it
 *      would hold and nothing is held. In `enforce`, if every recipient is held the send is aborted; if only some are, those recipients
 *      are removed and the rest are sent to.
 *
 * IT NEVER THROWS, and that is now true of the LOG as well (an earlier version said it and was not: a log channel that throws -- an
 * unwritable file, a full disk -- threw out of the `catch`'s own `Log::warning`, and the send failed). Every line this class writes is
 * guarded, and the DECISION is made before it is written, so a broken log can neither stop an email nor release a held one. Any other
 * failure is logged with the exception CLASS only. What the failure DECIDES depends on what was already known: if the sender had been
 * classified as marketing and the mode is `enforce`, the message is HELD -- the gate's own rule is that marketing which cannot be verified
 * is held -- and in every other case it goes, because a message nobody could identify is not one to stop.
 *
 * WHAT IT COSTS. In `off`: the container builds this class and its two collaborators on each dispatch, then one configuration read and
 * one comparison. Nothing else: no classification, no query, no backtrace, no log.
 */
final class EnforceMarketingConsentOnMail
{
    /** @var array<string, true> senders already reported in this process, so a campaign of ten thousand writes one line, not ten thousand */
    private static array $reported = [];

    public function __construct(
        private readonly MarketingSendGate $gate,
        private readonly MailSenderClassifier $classifier,
    ) {}

    /** For tests: the once-per-sender memo is process-wide state. */
    public static function forgetReported(): void
    {
        self::$reported = [];
    }

    public function handle(MessageSending $event): ?bool
    {
        $sender = null;

        try {
            // 1. Inert unless switched on.
            if ($this->gate->mode(Channel::Email) === MarketingSendGate::MODE_OFF) {
                return null;
            }

            // 2. Who is this?
            $sender = $this->classifier->classify($event);

            if (! $sender->isDecided()) {
                $this->reportUndecided($sender);

                return null;
            }

            // 3. Only marketing needs a permission.
            if (! $sender->class->needsConsent()) {
                return null;
            }

            // 4. Marketing: one decision per recipient.
            return $this->gateRecipients($event->message, $sender);
        } catch (Throwable $e) {
            try {
                Log::warning('Marketing consent listener failed', ['exception' => get_class($e)]);
            } catch (Throwable) {
                // a broken log must not change what happens to the message
            }

            // Marketing that cannot be verified is held under `enforce`; everything else goes.
            return $sender?->class?->needsConsent() === true && $this->enforcing() ? false : null;
        }
    }

    private function enforcing(): bool
    {
        try {
            return $this->gate->mode(Channel::Email) === MarketingSendGate::MODE_ENFORCE;
        } catch (Throwable) {
            return false;
        }
    }

    private function gateRecipients(Email $message, MailSender $sender): ?bool
    {
        $held = [];
        $wouldHold = false;
        $addresses = $this->recipients($message); // distinct, case-insensitively

        foreach ($addresses as $address) {
            $decision = $this->gate->decide($sender->storeId ?? 0, Channel::Email, MessageClass::Marketing, $address);

            if (! $decision->send) {
                $held[strtolower($address)] = true;
            }

            $wouldHold = $wouldHold || $decision->wouldHold;
        }

        // The gate logs per recipient WHY (store, reason, a hash prefix) but not WHICH SENDER; this is the line that says which campaign.
        // Written after the decision is known and guarded, so it can neither change it nor throw.
        if ($held !== [] || $wouldHold) {
            $this->logSender($held !== [], $sender, count($addresses), count($held));
        }

        if ($held === []) {
            return null;
        }

        if (count($held) === count($addresses)) {
            return false;
        }

        $this->removeRecipients($message, $held);

        return null;
    }

    private function logSender(bool $enforced, MailSender $sender, int $recipients, int $held): void
    {
        $context = ['sender' => $sender->label(), 'store_id' => $sender->storeId, 'recipients' => $recipients, 'held' => $held];

        try {
            if ($enforced) {
                Log::warning('Marketing email held by consent enforcement', $context);
            } else {
                Log::warning('Marketing email would be held by consent enforcement (report mode; it was sent)', $context);
            }
        } catch (Throwable) {
            // see handle(): a broken log changes nothing
        }
    }

    /** @return list<string> every distinct To, Cc and Bcc address */
    private function recipients(Email $message): array
    {
        $out = [];

        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $list) {
            foreach ($list as $address) {
                $out[strtolower($address->getAddress())] = $address->getAddress();
            }
        }

        return array_values($out);
    }

    /** @param  array<string, true>  $held lower-cased addresses to drop from To, Cc and Bcc */
    private function removeRecipients(Email $message, array $held): void
    {
        $keep = fn (array $list): array => array_values(array_filter($list, fn (Address $a) => ! isset($held[strtolower($a->getAddress())])));

        $message->to(...$keep($message->getTo()));
        $message->cc(...$keep($message->getCc()));
        $message->bcc(...$keep($message->getBcc()));
    }

    private function reportUndecided(MailSender $sender): void
    {
        $site = self::callSite();

        // A raw message and an anonymous class name no sender of their own, so their LABEL is the same for every call site in the product:
        // keyed on it alone, only the first one per process would ever be reported. Everything else is identified by its class.
        $key = $sender->kind === MailSender::KIND_RAW || str_starts_with($sender->origin, 'anonymous ')
            ? $sender->label().'@'.$site
            : $sender->label();

        if (isset(self::$reported[$key])) {
            return;
        }

        self::$reported[$key] = true;

        try {
            Log::warning('Email sender is not classified for marketing consent', [
                'kind' => $sender->kind,
                'sender' => $sender->label(),
                'store_id' => $sender->storeId,
                'sent_from' => $site,
                'mode' => $this->gate->mode(Channel::Email),
            ]);
        } catch (Throwable) {
            // see handle(): a broken log changes nothing
        }
    }

    /** The first frame outside the framework: where the message was actually sent from. A path, never an address. */
    private static function callSite(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || str_contains($file, '/vendor/laravel/framework/') || str_ends_with($file, 'EnforceMarketingConsentOnMail.php')) {
                continue;
            }

            return ltrim(str_replace(base_path(), '', $file), '/').':'.($frame['line'] ?? '?');
        }

        return null;
    }
}
