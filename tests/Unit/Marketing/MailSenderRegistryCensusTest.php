<?php

namespace Tests\Unit\Marketing;

use App\Support\Marketing\MailSenderRegistry;
use App\Support\Marketing\MessageClass;
use PHPUnit\Framework\TestCase;

/**
 * A NEW EMAIL SENDER CANNOT BE ADDED WITHOUT SOMEBODY CHOOSING WHAT IT IS FOR. D8 / GAP-200 / MKT-008.
 *
 * `MailSenderRegistry` is a list of decisions. This census fails when the tree contains a Mailable class, or a template name passed
 * to `sendEmailTemplate()` or looked up in `email_templates`, that the registry does not list -- because an unlisted sender is
 * UNDECIDED, an undecided sender is reported and never held, and "reported and never held" is how a promotional message gets
 * through unnoticed. It also fails on a registry entry for something that no longer exists, so the list cannot rot into a
 * reassurance.
 *
 * It reads comment-stripped source, so prose that names a class or a template cannot satisfy or trip it. WHAT IT READS: every class that
 * extends `Mailable` (also through an `as` alias, `readonly`, several classes in a file, or `CommonEmailTemplate`), every class that extends
 * `Notification`, every template name passed to `sendEmailTemplate('X'`, `sendOwnerEmailTemplate('X'`, `EmailTemplate::sendEmailTemplate('X'`,
 * `EmailTemplate::where('name', 'X')` or `$emailTemplate = 'X'` / `['X', ...]`, and any `Mail::raw()` / `Mail::html()` / `Mail::send('view')`.
 * WHAT IT CANNOT SEE: a template name assembled at run time. The runtime report of undecided senders is the net under that.
 */
class MailSenderRegistryCensusTest extends TestCase
{
    /** @return array<string, string> repo-relative path => comment-stripped source */
    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        $out = [];

        foreach (['app', 'packages/workdo'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = '';

                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (is_array($token)) {
                        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        $code .= $token[1];
                    } else {
                        $code .= $token;
                    }
                }

                $out[substr($file->getPathname(), strlen($root) + 1)] = $code;
            }
        }

        // The registry names every sender in strings and so would satisfy its own census.
        unset($out['app/Support/Marketing/MailSenderRegistry.php']);

        return $out;
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $bases  the short names a class may extend to count (resolved per file, so an `as` alias is followed)
     * @param  string  $import  the fully-qualified class whose `use` statement may carry an alias
     * @return list<string> fully-qualified class names
     */
    private function classesExtending(array $sources, array $bases, string $import): array
    {
        $found = [];

        foreach ($sources as $code) {
            $names = $bases;

            if (preg_match('/^\s*use\s+'.preg_quote($import, '/').'\s+as\s+(\w+)\s*;/m', $code, $alias)) {
                $names[] = $alias[1];
            }

            $namespace = preg_match('/^\s*namespace\s+([^;]+);/m', $code, $ns) ? trim($ns[1]) : '';
            $extends = implode('|', array_map('preg_quote', $names));

            if (preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)\s+extends\s+\\\\?(?:'.preg_quote($import, '/').'|'.$extends.')\b/m', $code, $m)) {
                foreach ($m[1] as $class) {
                    $found[] = ltrim($namespace.'\\'.$class, '\\');
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /** @param  array<string, string>  $sources */
    private function mailableClasses(array $sources): array
    {
        return array_values(array_unique(array_merge(
            $this->classesExtending($sources, ['Mailable'], 'Illuminate\\Mail\\Mailable'),
            $this->classesExtending($sources, ['CommonEmailTemplate'], 'App\\Mail\\CommonEmailTemplate'),
        )));
    }

    /** @param  array<string, string>  $sources */
    private function notificationClasses(array $sources): array
    {
        return $this->classesExtending($sources, ['Notification'], 'Illuminate\\Notifications\\Notification');
    }

    /**
     * @param  array<string, string>  $sources
     * @return list<string> every template name the tree passes to a send helper or looks up by an exact name
     */
    private function templateNames(array $sources): array
    {
        $found = [];

        foreach ($sources as $code) {
            foreach ([
                '/(?:sendEmailTemplate|sendOwnerEmailTemplate)\(\s*[\'"]([^\'"]+)[\'"]/',
                '/EmailTemplate::where\(\s*[\'"]name[\'"]\s*,\s*[\'"]([^\'"%]+)[\'"]/',
                '/\$emailTemplate\s*=\s*[\'"]([^\'"]+)[\'"]/',
            ] as $pattern) {
                if (preg_match_all($pattern, $code, $m)) {
                    foreach ($m[1] as $name) {
                        // `where('name', 'LIKE', $x)` captures the operator, which is not a name.
                        if (strtoupper($name) !== 'LIKE') {
                            $found[$name] = true;
                        }
                    }
                }
            }

            // The array form: `$emailTemplate = ['Lottery Win', 'Lottery Fail'];`, the shape the modules use to seed their rows.
            if (preg_match_all('/\$emailTemplate\s*=\s*\[([^\]]*)\]/s', $code, $arrays)) {
                foreach ($arrays[1] as $list) {
                    if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $list, $names)) {
                        foreach ($names[1] as $name) {
                            $found[$name] = true;
                        }
                    }
                }
            }
        }

        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, string>  $sources
     * @return list<string> the files that send a message built without a Mailable or a Notification
     */
    private function rawSenders(array $sources): array
    {
        $found = [];

        foreach ($sources as $file => $code) {
            if (preg_match('/Mail::(?:raw|html|plain)\s*\(|Mail::send\(\s*[\'"\[]/', $code)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    public function test_every_mailable_class_in_the_tree_is_in_the_registry(): void
    {
        $missing = array_values(array_diff($this->mailableClasses($this->sources()), array_keys(MailSenderRegistry::MAILABLES)));

        $this->assertSame([], $missing,
            "These Mailable classes are not in MailSenderRegistry::MAILABLES, so every send of them is an UNDECIDED sender -- reported, never held. "
            ."Read what sends each one and say what it is for (or say `null` and why):\n  ".implode("\n  ", $missing));
    }

    public function test_every_registry_mailable_still_exists(): void
    {
        $stale = array_values(array_diff(array_keys(MailSenderRegistry::MAILABLES), $this->mailableClasses($this->sources())));

        $this->assertSame([], $stale, 'These registry entries name a Mailable that no longer exists: '.implode(', ', $stale));
    }

    public function test_every_template_name_in_the_tree_is_in_the_registry(): void
    {
        $missing = array_values(array_diff($this->templateNames($this->sources()), array_keys(MailSenderRegistry::TEMPLATES)));

        $this->assertSame([], $missing,
            "These email template names are not in MailSenderRegistry::TEMPLATES: ".implode(', ', $missing));
    }

    public function test_every_registry_template_is_still_used_somewhere(): void
    {
        $stale = array_values(array_diff(array_keys(MailSenderRegistry::TEMPLATES), $this->templateNames($this->sources())));

        $this->assertSame([], $stale, 'These registry entries name a template nothing sends or looks up: '.implode(', ', $stale));
    }

    public function test_every_entry_says_why_and_the_carrier_is_the_only_class_decided_by_template(): void
    {
        foreach (MailSenderRegistry::MAILABLES as $class => $entry) {
            $this->assertNotSame('', trim($entry['basis']), "{$class} has no stated basis");
            $this->assertTrue(
                $entry['class'] === null || $entry['class'] instanceof MessageClass || $entry['class'] === MailSenderRegistry::BY_TEMPLATE,
                "{$class} has an invalid class"
            );

            if ($entry['class'] === MailSenderRegistry::BY_TEMPLATE) {
                $this->assertSame('App\\Mail\\CommonEmailTemplate', $class, 'only the carrier class is decided by its template');
            }
        }

        foreach (MailSenderRegistry::TEMPLATES as $name => $entry) {
            $this->assertNotSame('', trim($entry['basis']), "template {$name} has no stated basis");
            $this->assertTrue($entry['class'] === null || $entry['class'] instanceof MessageClass, "template {$name} has an invalid class");
        }
    }

    public function test_the_marketing_decisions_are_pinned_so_changing_one_is_deliberate(): void
    {
        $marketingMailables = array_keys(array_filter(MailSenderRegistry::MAILABLES, fn (array $e) => $e['class'] === MessageClass::Marketing));
        $marketingTemplates = array_keys(array_filter(MailSenderRegistry::TEMPLATES, fn (array $e) => $e['class'] === MessageClass::Marketing));
        sort($marketingTemplates);

        $this->assertSame(['Workdo\\CouponEmail\\app\\Emails\\CouponEmailTemplate'], $marketingMailables);
        $this->assertSame(['Abandon Cart', 'Abandon Wishlist', 'Review Reminder'], $marketingTemplates);
    }

    public function test_every_notification_class_that_mails_is_in_the_registry(): void
    {
        $classes = $this->notificationClasses($this->sources());

        $this->assertSame([], array_values(array_diff($classes, array_keys(MailSenderRegistry::NOTIFICATIONS))),
            'These Notification classes are not in MailSenderRegistry::NOTIFICATIONS: '.implode(', ', array_diff($classes, array_keys(MailSenderRegistry::NOTIFICATIONS))));
        $this->assertSame([], array_values(array_diff(array_keys(MailSenderRegistry::NOTIFICATIONS), $classes)), 'a registry notification no longer exists');
    }

    public function test_no_message_is_sent_without_a_mailable_or_a_notification(): void
    {
        // A raw message names no sender, so it is undecided BY CONSTRUCTION: it is reported at runtime and never held. There are none in the
        // tree today; one appearing is a decision for a person, not something to find in a log.
        $this->assertSame([], $this->rawSenders($this->sources()),
            'These files send a raw message (Mail::raw/html/plain or Mail::send(\'view\')), which no registry can classify: '.implode(', ', $this->rawSenders($this->sources())));
    }

    /**
     * EVERY DECISION, PINNED. A test that only checks "each entry has a basis" cannot tell a decided-transactional sender from an undecided one,
     * and both pass through the seam, so a wrong decision would otherwise change nothing visible. Changing any line below is a decision about
     * whether a kind of email may be held, and belongs in a diff a person reads.
     */
    public function test_every_decision_is_pinned(): void
    {
        $decided = fn (array $entries) => array_map(fn (array $e) => $e['class'] instanceof MessageClass ? $e['class']->value : ($e['class'] ?? 'undecided'), $entries);

        $this->assertSame([
            'App\\Mail\\CommonEmailTemplate' => 'by_template',
            'App\\Mail\\SendOTP' => 'transactional',
            'App\\Mail\\OTPVerify' => 'transactional',
            'App\\Mail\\TestMail' => 'operational',
            'App\\Mail\\ProdcutMail' => 'transactional',
            'App\\Mail\\ProdcutStockMail' => 'undecided',
            'App\\Mail\\SendLoginDetail' => 'undecided',
            'App\\Mail\\invoicePay' => 'undecided',
            'Workdo\\CouponEmail\\app\\Emails\\CouponEmailTemplate' => 'marketing',
            'Workdo\\EMailBox\\app\\Emails\\EmailsSendMail' => 'undecided',
            'Workdo\\EMailBox\\app\\Emails\\ReplyMail' => 'undecided',
            'Workdo\\PDFVouchers\\app\\Mail\\CouponStatusMail' => 'undecided',
        ], $decided(MailSenderRegistry::MAILABLES));

        $this->assertSame([
            'Order Created' => 'transactional',
            'Order Created For Owner' => 'operational',
            'Status Change' => 'transactional',
            'Abandon Cart' => 'marketing',
            'Abandon Wishlist' => 'marketing',
            'Review Reminder' => 'marketing',
            'Stock Status' => 'undecided',
            'Payment Reminder' => 'undecided',
            'Product Catelog' => 'undecided',
            'Product Enquiry' => 'undecided',
            'Lucky Winner' => 'undecided',
            'Lottery Win' => 'undecided',
            'Lottery Fail' => 'undecided',
            'Lottery Remainder' => 'operational',
            'Pre Order Notification' => 'transactional',
            'Pre Order Created' => 'transactional',
            'Pre Order Created For Owner' => 'operational',
            'New Helpdesk Ticket' => 'undecided',
            'New Helpdesk Ticket Reply' => 'undecided',
        ], $decided(MailSenderRegistry::TEMPLATES));

        $this->assertSame([
            'App\\Notifications\\ResetPasswordNotification' => 'transactional',
            'App\\Notifications\\CustomResetPasswordNotification' => 'transactional',
        ], $decided(MailSenderRegistry::NOTIFICATIONS));
    }

    public function test_the_template_lookup_ignores_case_and_surrounding_space_like_the_like_that_finds_the_row(): void
    {
        foreach (['abandon cart', 'ABANDON CART', '  Abandon Cart  '] as $spelling) {
            $this->assertSame(MessageClass::Marketing, MailSenderRegistry::template($spelling)['class'] ?? null, $spelling);
        }

        $this->assertNull(MailSenderRegistry::template('Abandon Carts'), 'a different name must not match');
    }

    public function test_the_census_would_catch_a_new_sender_and_a_new_template(): void
    {
        // A census that cannot fail is a trap. The extractors run here over SYNTHETIC sources.
        $this->assertSame(['App\\Mail\\FlashSale'], $this->mailableClasses([
            'app/Mail/FlashSale.php' => "<?php\nnamespace App\\Mail;\nuse Illuminate\\Mail\\Mailable;\nclass FlashSale extends Mailable {}\n",
        ]));
        $this->assertSame(['Workdo\\X\\Y'], $this->mailableClasses([
            'packages/workdo/X/Y.php' => "<?php\nnamespace Workdo\\X;\nclass Y extends \\Illuminate\\Mail\\Mailable {}\n",
        ]));
        $this->assertSame(['Flash Sale'], $this->templateNames(['app/X.php' => "Utility::sendEmailTemplate('Flash Sale', \$to, \$d, \$o, \$s, \$id);"]));
        $this->assertSame(['Flash Sale'], $this->templateNames(['packages/workdo/X/C.php' => "self::sendOwnerEmailTemplate('Flash Sale', \$to, \$n, \$d, \$u, \$s);"]), 'the vendored idiom');
        $this->assertSame(['Flash Sale', 'Win Back'], $this->templateNames(['packages/workdo/X/C.php' => "\$emailTemplate = [\n 'Flash Sale',\n 'Win Back',\n];"]), 'the array form');
        $this->assertSame(['App\\Mail\\Promo'], $this->mailableClasses(['app/Mail/Promo.php' => "<?php\nnamespace App\\Mail;\nuse App\\Mail\\CommonEmailTemplate;\nclass Promo extends CommonEmailTemplate {}\n"]), 'a subclass of the carrier');
        $this->assertSame(['App\\Mail\\A', 'App\\Mail\\B'], $this->mailableClasses(['app/Mail/Two.php' => "<?php\nnamespace App\\Mail;\nuse Illuminate\\Mail\\Mailable as Base;\nclass A extends Base {}\nreadonly class B extends Base {}\n"]), 'an alias, readonly, and two classes in one file');
        $this->assertSame(['App\\Notifications\\Promo'], $this->notificationClasses(['app/Notifications/Promo.php' => "<?php\nnamespace App\\Notifications;\nuse Illuminate\\Notifications\\Notification;\nclass Promo extends Notification {}\n"]));
        $this->assertSame(['app/X.php'], $this->rawSenders(['app/X.php' => "Mail::raw('x', fn (\$m) => \$m->to(\$a));", 'app/Y.php' => 'Mail::to($a)->send($mailable);']), 'a raw send, and not a Mailable send');
        $this->assertSame(['Win Back'], $this->templateNames(['packages/workdo/X/C.php' => "EmailTemplate::where('name', 'Win Back')->first();"]));
        $this->assertSame([], $this->templateNames(['packages/workdo/X/C.php' => "EmailTemplate::where('name', 'LIKE', \$emailTemplate)->first();"]), 'a LIKE operator is not a name');

        $this->assertGreaterThan(500, count($this->sources()), 'the census read almost nothing, so passing proves nothing');
        $this->assertGreaterThan(8, count($this->mailableClasses($this->sources())));
    }
}
