<?php

namespace Tests\Feature\Marketing;

use App\Mail\CommonEmailTemplate;
use App\Mail\SendOTP;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Store;
use App\Models\User;
use App\Services\Marketing\ConsentLedger;
use App\Services\Marketing\EnforceMarketingConsentOnMail;
use App\Services\Marketing\MailSenderClassifier;
use App\Support\Marketing\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * THE EMAIL SEAM, END TO END: a real Mailable, the real mailer, the array transport, the real listener, the real gate and ledger.
 * D8 / GAP-200 / MKT-008.
 *
 * What these cases defend, in the order a merchant and then a regulator would ask:
 *
 *   * with the shipped default the seam is INERT -- nothing is read, nothing is held, nothing is logged -- because it sits on the path of
 *     every email the product sends;
 *   * a marketing email is held only when enforcement is on and consent is not recorded, per recipient, at the right store;
 *   * a receipt, a one-time code and a merchant's own notice are never held;
 *   * a sender nobody has classified is REPORTED, not guessed, and is not held;
 *   * the seam can never stop an email by failing, and never writes an address to a log.
 */
class MailConsentSeamTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 31;
    private const OTHER_STORE = 32;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        EnforceMarketingConsentOnMail::forgetReported();
        $this->logged = [];

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    private function mode(string $mode): void
    {
        config(['marketing_consent.enforcement' => $mode]);
    }

    /** A real `CommonEmailTemplate`, built the way `Utility::sendEmailTemplate` builds it, for the template with this name. */
    private function carrier(string $templateName, ?int $storeId = self::STORE, mixed $store = null): CommonEmailTemplate
    {
        $creator = User::query()->first() ?? User::factory()->create(['type' => 'admin']);
        $parent = EmailTemplate::forceCreate(['name' => $templateName, 'from' => 'shop@example.com', 'created_by' => $creator->id]);

        $lang = new EmailTemplateLang();
        $lang->parent_id = $parent->id;
        $lang->subject = 'A message';
        $lang->content = '<p>Body</p>';
        $lang->from = 'shop@example.com';

        // Production passes a `Store` MODEL (sendEmailTemplate's `$store`); the other tests pass an array, and one passes a real model.
        $store ??= $storeId === null ? ['name' => 'Shop'] : ['id' => $storeId, 'name' => 'Shop'];

        return new CommonEmailTemplate($lang, ['MAIL_FROM_ADDRESS' => 'shop@example.com'], $store);
    }

    /** @return list<list<string>> the To addresses of every message the array transport received */
    private function delivered(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => array_map(fn ($a) => $a->getAddress(), $sent->getOriginalMessage()->getTo()))
            ->values()->all();
    }

    private function grant(string $address, int $store = self::STORE): void
    {
        (new ConsentLedger())->grant($store, Channel::Email, $address, 'checkout_checkbox');
    }

    /** @return list<string> */
    private function messages(string $level = 'warning'): array
    {
        return array_values(array_map(fn ($l) => $l['message'], array_filter($this->logged, fn ($l) => $l['level'] === $level)));
    }

    // ---------------------------------------------------------------- inert by default

    public function test_off_is_inert_nothing_is_read_held_or_logged(): void
    {
        $this->mode('off');
        $mail = $this->carrier('Abandon Cart'); // the fixture's own insert must not count as the seam's read
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'email_templates') || str_contains($q->sql, 'marketing_')) {
                $queries[] = $q->sql;
            }
        });

        Mail::to('buyer@example.com')->send($mail);
        Mail::raw('x', fn ($m) => $m->to('raw@example.com')->subject('raw'));

        $this->assertSame([['buyer@example.com'], ['raw@example.com']], $this->delivered(), 'off held something');
        $this->assertSame([], $queries, 'an off seam read a template or a consent table; it sits on the path of every email');
        $this->assertSame([], $this->messages(), 'an off seam wrote a log line');
    }

    // ---------------------------------------------------------------- marketing

    public function test_report_mode_sends_a_marketing_email_and_says_what_enforcement_would_do(): void
    {
        $this->mode('report');

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart'));

        $this->assertSame([['buyer@example.com']], $this->delivered());
        $this->assertContains('Marketing message would be held by consent enforcement (report mode; it was sent)', $this->messages());
        $this->assertContains('Marketing email would be held by consent enforcement (report mode; it was sent)', $this->messages(), 'the line that says WHICH sender is missing');
        $this->assertTrue($this->contextHas('sender', 'App\\Mail\\CommonEmailTemplate [Abandon Cart]'));
    }

    public function test_enforce_holds_a_marketing_email_with_no_recorded_consent(): void
    {
        $this->mode('enforce');

        $result = Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart'));

        $this->assertNull($result, 'a held send returns nothing');
        $this->assertSame([], $this->delivered(), 'a marketing email with no consent was delivered');
        $this->assertContains('Marketing message held by consent enforcement', $this->messages());
        $this->assertContains('Marketing email held by consent enforcement', $this->messages(), 'the line that says WHICH sender was held is missing');
        $this->assertTrue($this->contextHas('sender', 'App\\Mail\\CommonEmailTemplate [Abandon Cart]'));
    }

    public function test_enforce_sends_a_marketing_email_to_a_recipient_who_granted(): void
    {
        $this->mode('enforce');
        $this->grant('buyer@example.com');

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart'));

        $this->assertSame([['buyer@example.com']], $this->delivered());
    }

    public function test_enforce_holds_a_withdrawn_or_suppressed_recipient_even_after_a_grant(): void
    {
        $this->mode('enforce');
        $ledger = new ConsentLedger();
        $this->grant('gone@example.com');
        $ledger->withdraw(self::STORE, Channel::Email, 'gone@example.com', 'unsubscribe_link');
        $this->grant('angry@example.com');
        $ledger->suppress(self::STORE, Channel::Email, 'angry@example.com', 'complaint_feedback');

        foreach (['gone@example.com', 'angry@example.com'] as $address) {
            Mail::to($address)->send($this->carrier('Abandon Wishlist'));
        }

        $this->assertSame([], $this->delivered());
    }

    public function test_one_stores_consent_does_not_permit_another_stores_email(): void
    {
        $this->mode('enforce');
        $this->grant('buyer@example.com', self::OTHER_STORE);

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart', self::STORE));

        $this->assertSame([], $this->delivered(), "another store's consent permitted this store's marketing");
    }

    public function test_a_marketing_email_that_cannot_be_attributed_to_a_store_is_held_when_enforced(): void
    {
        $this->mode('enforce');
        $this->grant('buyer@example.com');

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart', storeId: null));

        $this->assertSame([], $this->delivered(), 'consent is per store, so a store-less marketing email has nothing to match');
        $this->assertTrue($this->contextHas('reason', 'no_store'));
    }

    public function test_only_the_recipients_without_consent_are_removed_from_a_multi_recipient_message(): void
    {
        $this->mode('enforce');
        $this->grant('yes@example.com');

        Mail::to('yes@example.com')->cc('no@example.com')->bcc('also-no@example.com')->send($this->carrier('Abandon Cart'));

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $original = $sent[0]->getOriginalMessage();
        $this->assertSame(['yes@example.com'], array_map(fn ($a) => $a->getAddress(), $original->getTo()));
        $this->assertSame([], $original->getCc(), 'a recipient without consent stayed on the message');
        $this->assertSame([], $original->getBcc());
    }

    public function test_a_message_whose_every_recipient_is_held_is_not_sent_at_all(): void
    {
        $this->mode('enforce');

        Mail::to('a@example.com')->cc('b@example.com')->send($this->carrier('Abandon Cart'));

        $this->assertSame([], $this->delivered());
    }

    // ---------------------------------------------------------------- never held

    public function test_enforce_never_holds_a_receipt_a_one_time_code_or_a_merchants_own_notice(): void
    {
        $this->mode('enforce');

        Mail::to('buyer@example.com')->send($this->carrier('Order Created'));
        Mail::to('owner@example.com')->send($this->carrier('Order Created For Owner'));
        Mail::to('buyer@example.com')->send($this->carrier('Status Change'));
        Mail::to('buyer@example.com')->send(new SendOTP('123456'));

        $this->assertSame([['buyer@example.com'], ['owner@example.com'], ['buyer@example.com'], ['buyer@example.com']], $this->delivered());
        $this->assertSame([], array_filter($this->messages(), fn ($m) => str_contains($m, 'held')), 'a service message was logged as held');
        // They pass because they are DECIDED, not because nobody could tell what they were: an undecided sender passes too, and would be reported.
        $this->assertNotContains('Email sender is not classified for marketing consent', $this->messages(), 'a decided sender was treated as undecided');
    }

    // ---------------------------------------------------------------- undecided senders

    public function test_an_undecided_template_is_sent_and_reported_once_with_where_it_came_from(): void
    {
        $this->mode('enforce');

        Mail::to('a@example.com')->send($this->carrier('Stock Status'));
        Mail::to('b@example.com')->send($this->carrier('Stock Status'));

        $this->assertSame([['a@example.com'], ['b@example.com']], $this->delivered(), 'an undecided sender was held: nobody can say it is not a receipt');

        $reports = array_values(array_filter($this->logged, fn ($l) => $l['message'] === 'Email sender is not classified for marketing consent'));
        $this->assertCount(1, $reports, 'a campaign would write one line per message');
        $this->assertSame('App\\Mail\\CommonEmailTemplate [Stock Status]', $reports[0]['context']['sender']);
        $this->assertSame(self::STORE, $reports[0]['context']['store_id']);
        $this->assertStringContainsString('MailConsentSeamTest.php', (string) $reports[0]['context']['sent_from'], 'the call site was not recovered');
    }

    public function test_a_raw_message_and_an_anonymous_mailable_are_undecided_and_not_held(): void
    {
        $this->mode('enforce');

        Mail::raw('x', fn ($m) => $m->to('raw@example.com')->subject('raw'));
        Mail::to('anon@example.com')->send(new class extends Mailable {
            public function build()
            {
                return $this->subject('anon')->html('<p>x</p>');
            }
        });

        $this->assertSame([['raw@example.com'], ['anon@example.com']], $this->delivered());

        $senders = array_map(fn ($l) => $l['context']['sender'], array_filter($this->logged, fn ($l) => $l['message'] === 'Email sender is not classified for marketing consent'));
        $this->assertEqualsCanonicalizing(['raw', 'anonymous Mailable'], array_values($senders));
        foreach ($senders as $label) {
            $this->assertStringNotContainsString("\0", $label, 'an anonymous class name leaked its NUL byte into a log line');
        }
    }

    // ---------------------------------------------------------------- it can never stop an email by failing

    public function test_a_failure_inside_the_seam_never_stops_an_email(): void
    {
        $this->mode('enforce');

        $this->app->instance(MailSenderClassifier::class, new class extends MailSenderClassifier {
            public function classify(MessageSending $event): \App\Support\Marketing\MailSender
            {
                throw new \RuntimeException('boom with buyer@example.com in the message');
            }
        });

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart'));

        $this->assertSame([['buyer@example.com']], $this->delivered());
        $failure = array_values(array_filter($this->logged, fn ($l) => str_contains($l['message'], 'listener failed')));
        $this->assertCount(1, $failure);
        $this->assertSame(['exception' => \RuntimeException::class], $failure[0]['context'], 'the log carried more than the exception class');
    }

    public function test_an_unreadable_consent_store_holds_marketing_and_nothing_else_when_enforced(): void
    {
        $this->mode('enforce');
        DB::statement('DROP TABLE marketing_contact_states');

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart'));
        Mail::to('buyer@example.com')->send($this->carrier('Order Created'));

        $this->assertSame([['buyer@example.com']], $this->delivered(), 'only the receipt should have gone');
    }

    // ---------------------------------------------------------------- hygiene

    public function test_no_log_line_the_seam_writes_carries_an_address(): void
    {
        $this->mode('enforce');

        Mail::to('private.person@example.com')->send($this->carrier('Abandon Cart'));
        Mail::to('private.person@example.com')->send($this->carrier('Stock Status'));
        Mail::raw('x', fn ($m) => $m->to('private.person@example.com')->subject('raw'));

        $this->assertNotEmpty($this->logged);
        $encoded = json_encode(array_map(fn ($l) => [$l['message'], $l['context']], $this->logged));
        $this->assertStringNotContainsString('private.person', $encoded);
        $this->assertStringNotContainsString('example.com', $encoded);
    }

    public function test_the_listener_is_registered_exactly_once(): void
    {
        // The framework's EventServiceProvider discovers `app/Listeners` by `handle()` signature, so a listener placed there AND listed
        // explicitly runs twice per message. This one is deliberately outside that directory.
        Artisan::call('event:list', ['--event' => 'MessageSending']);
        $listeners = array_values(array_filter(
            array_map('trim', explode("\n", Artisan::output())),
            fn (string $line) => str_starts_with($line, '⇂')
        ));

        $ours = array_filter($listeners, fn ($l) => str_contains($l, 'EnforceMarketingConsentOnMail'));
        $this->assertCount(1, $ours, 'the consent listener is registered '.count($ours).' times: '.implode(', ', $listeners));
    }

    // ---------------------------------------------------------------- the classifier on events the test suite cannot produce

    public function test_a_vendored_coupon_mail_is_marketing_and_carries_no_store(): void
    {
        // The vendored class is not loaded in this suite, but the event only names it, so the event can be built by hand.
        $event = new MessageSending((new Email())->to('buyer@example.com'), ['__laravel_mailable' => 'Workdo\\CouponEmail\\app\\Emails\\CouponEmailTemplate']);

        $sender = app(MailSenderClassifier::class)->classify($event);

        $this->assertTrue($sender->isDecided());
        $this->assertTrue($sender->class->needsConsent());
        $this->assertNull($sender->storeId, 'the coupon mail carries no store: it cannot be attributed from the mail event');

        $this->mode('enforce');
        $this->grant('buyer@example.com');
        $this->assertFalse(app(EnforceMarketingConsentOnMail::class)->handle($event), 'an unattributable marketing email was allowed');
    }

    private function contextHas(string $key, string $value): bool
    {
        foreach ($this->logged as $line) {
            if (($line['context'][$key] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- what the second review found

    public function test_a_store_model_attributes_the_message_to_its_store(): void
    {
        // Production's `$store` is a Store MODEL, not an array. A broken `is_object` arm would make every marketing email `no_store`:
        // all of it held under enforce, including consented contacts, with every array-based test still green.
        $this->mode('enforce');
        $this->grant('buyer@example.com');
        $store = (new Store())->forceFill(['id' => self::STORE, 'name' => 'Shop']);

        Mail::to('buyer@example.com')->send($this->carrier('Abandon Cart', store: $store));

        $this->assertSame([['buyer@example.com']], $this->delivered(), 'a Store model was not read as a store');
    }

    public function test_recipients_are_matched_ignoring_case_and_a_held_to_with_a_kept_cc_is_handled(): void
    {
        $this->mode('enforce');
        $this->grant('yes@example.com');

        // To is held (mixed case), Cc is consented (mixed case), Bcc repeats the held To in another case.
        Mail::to('NO@Example.COM')->cc('Yes@Example.com')->bcc('No@EXAMPLE.com')->send($this->carrier('Abandon Cart'));

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent, 'the consented Cc recipient was not sent to');
        $original = $sent[0]->getOriginalMessage();
        $this->assertSame([], $original->getTo(), 'a held To stayed on the message');
        $this->assertSame(['Yes@Example.com'], array_map(fn ($a) => $a->getAddress(), $original->getCc()));
        $this->assertSame([], $original->getBcc(), 'the held address stayed on as a Bcc under another spelling of its case');
    }

    public function test_a_template_stored_in_another_case_is_still_the_template_that_was_decided(): void
    {
        // The row that is SENT is found with `LIKE`, which is case-insensitive; the decision must be found the same way.
        $this->mode('enforce');

        Mail::to('buyer@example.com')->send($this->carrier('abandon cart'));

        $this->assertSame([], $this->delivered(), "a lower-case 'abandon cart' slipped past the 'Abandon Cart' decision");
    }

    public function test_a_log_channel_that_throws_never_stops_or_releases_an_email(): void
    {
        // An unwritable log file or a full disk makes `Log::warning` throw. The seam's own lines used to throw out of its `catch`, and the send
        // failed; and the gate returned its hold only AFTER its line was written, so a broken log could have turned a hold into an exception.
        Log::swap(new class {
            public function warning(...$args): void
            {
                throw new \RuntimeException('disk full');
            }

            public function __call($method, $args)
            {
                return null;
            }
        });

        $this->mode('report');
        Mail::to('a@example.com')->send($this->carrier('Stock Status')); // undecided: its first report line throws
        Mail::to('a2@example.com')->send($this->carrier('Abandon Cart')); // would-hold: the gate's and the seam's lines throw

        $this->mode('enforce');
        Mail::to('b@example.com')->send($this->carrier('Abandon Cart')); // must STAY held although its line cannot be written
        $this->grant('c@example.com');
        Mail::to('c@example.com')->send($this->carrier('Abandon Cart')); // consented: goes

        $this->assertSame([['a@example.com'], ['a2@example.com'], ['c@example.com']], $this->delivered());
    }

    public function test_a_failure_after_the_sender_is_known_to_be_marketing_holds_it_under_enforce_only(): void
    {
        // The recipients cannot be read: the failure lands AFTER classification, on a sender the registry says is marketing.
        $broken = new class extends Email {
            public function getTo(): array
            {
                throw new \RuntimeException('cannot read the recipients');
            }
        };
        $event = new MessageSending($broken, ['__laravel_mailable' => 'Workdo\\CouponEmail\\app\\Emails\\CouponEmailTemplate']);
        $listener = app(EnforceMarketingConsentOnMail::class);

        $this->mode('enforce');
        $this->assertFalse($listener->handle($event), 'marketing that could not be verified was allowed under enforce');

        $this->mode('report');
        $this->assertNull($listener->handle($event), 'report mode must never hold');

        $this->mode('off');
        $this->assertNull($listener->handle($event));
    }

    public function test_two_raw_senders_at_different_places_are_both_reported(): void
    {
        $this->mode('report');

        $this->rawSendFromOnePlace();
        $this->rawSendFromAnotherPlace();

        $reports = array_values(array_filter($this->logged, fn ($l) => $l['message'] === 'Email sender is not classified for marketing consent'));
        $this->assertCount(2, $reports, 'only the first raw sender per process was ever reported');
        $this->assertNotSame($reports[0]['context']['sent_from'], $reports[1]['context']['sent_from']);
    }

    private function rawSendFromOnePlace(): void
    {
        Mail::raw('x', fn ($m) => $m->to('one@example.com')->subject('one'));
    }

    private function rawSendFromAnotherPlace(): void
    {
        Mail::raw('x', fn ($m) => $m->to('two@example.com')->subject('two'));
    }

    public function test_notifications_are_classified_and_an_anonymous_one_is_not_logged_with_its_path(): void
    {
        $event = fn (string $class) => new MessageSending((new Email())->to('x@example.com'), ['__laravel_notification' => $class]);
        $classifier = app(MailSenderClassifier::class);

        $reset = $classifier->classify($event('App\\Notifications\\ResetPasswordNotification'));
        $this->assertTrue($reset->isDecided(), 'a password reset must not be an undecided sender');
        $this->assertFalse($reset->class->needsConsent());

        $anonymous = $classifier->classify($event("Illuminate\\Notifications\\Notification@anonymous\0/srv/app/Promo.php:12\$0"));
        $this->assertSame('anonymous Notification', $anonymous->origin);
        $this->assertFalse($anonymous->isDecided());
        $this->assertStringNotContainsString("\0", $anonymous->label());
    }

    public function test_a_broken_log_does_not_cost_a_consented_recipient_their_message(): void
    {
        // A partly-held message: one recipient consented, one did not. The seam logs WHICH SENDER after it has decided; if that line throws and
        // were not guarded, the failure would reach the outer catch, which holds marketing it cannot verify -- and the consented recipient
        // would lose a message because of a full disk.
        $this->grant('yes@example.com');
        $this->mode('enforce');
        Log::swap(new class {
            public function warning(...$args): void
            {
                throw new \RuntimeException('disk full');
            }

            public function __call($method, $args)
            {
                return null;
            }
        });

        Mail::to('yes@example.com')->cc('no@example.com')->send($this->carrier('Abandon Cart'));

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent, 'the consented recipient lost their message because the log is broken');
        $this->assertSame(['yes@example.com'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo()));
        $this->assertSame([], $sent[0]->getOriginalMessage()->getCc());
    }
}
