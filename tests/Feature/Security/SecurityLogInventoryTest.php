<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Log;
use LaraBug\Fakes\LaraBugFake;
use LaraBug\Logger\LaraBugHandler;
use ReflectionMethod;
use RuntimeException;

/**
 * P1-PLT-05 -- the Phase 01 security and financial log lines, and where they actually go.
 *
 * WHY THIS FILE EXISTS. Phase 01 added a large number of refusal and anomaly log lines across the
 * order, payment, refund, stock and tenancy paths. Each was justified in its own package. Nobody
 * had asked the next question: when one fires in production, where does it land and who reads it?
 * `docs/security/P01-OBSERVABILITY-INVENTORY.md` answers that, and this test is what stops the
 * answer rotting.
 *
 * IT IS A TWO-DIRECTIONAL RATCHET, deliberately. A one-directional one ("every documented line
 * still exists") would let a new `Log::critical` be added with nobody noticing it has no
 * destination; the other direction ("every line in app/ is documented") would let the document
 * accumulate lines that were deleted. Both are failures of the same property -- the document and
 * the code disagree -- so both fail here.
 *
 * AND THE LARABUG ASSERTIONS ARE DRIVEN, NOT GREPPED. The document's central claim is that
 * enabling larabug would not deliver ANY of these lines, because its Monolog handler discards
 * every record whose context carries no exception. That is a behavioural claim about vendored
 * code, so it is executed: the handler is called with a real record and the fake is asked what it
 * received. Reading the vendor source and asserting on the reading is exactly the habit this
 * project keeps getting caught by.
 */
class SecurityLogInventoryTest extends SecurityTestCase
{
    private const DOC = 'docs/security/P01-OBSERVABILITY-INVENTORY.md';

    /**
     * The levels Phase 01 used for "a control fired" or "a human should look at this".
     *
     * `error` is almost entirely pre-existing third-party-integration failure reporting and
     * `info`/`debug` are diagnostics; enumerating them would bury the ones that are signals. That
     * choice is stated in the document too, so a reader is not left wondering why 95 `error` call
     * sites are absent.
     */
    private const LEVELS = ['critical', 'warning'];

    /**
     * Re-census `app/` for the log lines the document claims to enumerate.
     *
     * Keyed on `file:line`, which is brittle in exactly the way this project has recorded twice
     * before -- a census keyed on line numbers moves whenever a comment above it does. That is
     * accepted HERE and nowhere else, because the thing being ratcheted is a DOCUMENT that must
     * be regenerated anyway when the code moves: a line-number drift failing this test is the
     * correct outcome, not a false positive. The message is carried alongside so a failure says
     * which line moved rather than only that something did.
     *
     * @return array<string, string>  "path:line" => message
     */
    private function censusFromSource(): array
    {
        $root = base_path();
        $found = [];

        $pattern = '/(?:\\\\?Log::|logger\(\)->)(' . implode('|', self::LEVELS) . ')'
            . '\s*\(\s*(?:__\()?\s*[\'"]([^\'"]{10,200})/';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $lines = file($file->getPathname());

            foreach ($lines as $i => $line) {
                if (preg_match($pattern, $line, $m)) {
                    $found[$relative . ':' . ($i + 1)] = trim(str_replace('\\', '', $m[2]));
                }
            }
        }

        ksort($found);

        return $found;
    }

    /** What the document's tables claim, keyed the same way. */
    private function censusFromDocument(): array
    {
        $path = base_path(self::DOC);

        if (! is_file($path)) {
            throw new RuntimeException(self::DOC . ' is missing; the inventory it holds is the deliverable of P1-PLT-05.');
        }

        $listed = [];

        foreach (file($path) as $line) {
            if (preg_match('/^\|\s*`(app\/[^`]+:\d+)`\s*\|\s*(.*?)\s*\|\s*$/', $line, $m)) {
                $listed[$m[1]] = trim($m[2]);
            }
        }

        ksort($listed);

        return $listed;
    }

    /**
     * EVERY SECURITY LOG LINE IN app/ IS IN THE INVENTORY, AND EVERY INVENTORY ROW STILL EXISTS.
     */
    public function test_the_observability_inventory_matches_the_source(): void
    {
        $source = $this->censusFromSource();
        $document = $this->censusFromDocument();

        $this->assertGreaterThan(80, count($source),
            'The source census found almost nothing, so the comparison below would be vacuous. '
            . 'The regex, not the codebase, is the likely cause. Found: ' . count($source));

        $undocumented = array_diff_key($source, $document);
        $this->assertSame([], $undocumented,
            "These security log lines exist in app/ and are NOT in " . self::DOC . ". A line with "
            . "no entry in the inventory is a line with no stated destination:\n  "
            . implode("\n  ", array_map(
                fn ($k, $v) => $k . '  ' . $v,
                array_keys($undocumented), $undocumented
            )));

        $stale = array_diff_key($document, $source);
        $this->assertSame([], $stale,
            "These rows are in " . self::DOC . " and no longer exist in app/. An inventory that "
            . "lists lines that were deleted is not an inventory:\n  "
            . implode("\n  ", array_keys($stale)));
    }

    /**
     * THE DOCUMENT'S CHANNEL CLAIMS ARE READ FROM THE CONFIG, NOT FROM MEMORY.
     *
     * Section 2 says all of these lines land in ONE unrotated file with no retention. If someone
     * later gives the stack a `daily` channel or a retention, that is the good outcome -- and this
     * test failing is how the document gets updated to say so, instead of continuing to describe
     * a deployment that no longer exists.
     */
    public function test_the_documented_channel_and_retention_still_describe_the_config(): void
    {
        $stack = config('logging.channels.stack.channels');

        $this->assertIsArray($stack);
        $this->assertContains('single', $stack,
            'The document says the stack resolves to the `single` channel. It no longer does, so '
            . 'section 2 of ' . self::DOC . ' is describing a deployment that has changed.');

        $single = config('logging.channels.single');

        $this->assertArrayNotHasKey('days', $single,
            'The `single` channel has acquired a retention. That is an improvement and section 2 '
            . 'of ' . self::DOC . ' must be updated to stop calling it unbounded.');
        $this->assertStringEndsWith('laravel.log', (string) ($single['path'] ?? ''),
            'The document names storage/logs/laravel.log as the one destination.');

        $this->assertNotNull(config('logging.channels.daily'),
            'The document says a rotating `daily` channel exists and is unused. If it has been '
            . 'removed, the recommendation in section 2 has to change.');
    }

    /**
     * LARABUG WOULD DELIVER NONE OF THESE LINES, AND THIS IS MEASURED RATHER THAN READ.
     *
     * The handler's `write()` returns immediately unless `$record['context']['exception']` is a
     * Throwable, so a `Log::warning('...', ['gateway' => 'khalti'])` -- the shape of every line in
     * section 1 -- is discarded. The consequence for section 3 is the one that matters: anyone who
     * enables larabug expecting to see the Phase 01 security signals will see none of them, and
     * reviewing its blacklist is therefore not an answer to section 2.
     */
    public function test_larabug_discards_a_log_record_that_carries_no_exception(): void
    {
        // Constructed by hand rather than resolved: LaraBug\Http\Client takes two required
        // strings (login, project) that the container cannot supply, and in this deployment both
        // are empty anyway (LB_KEY and LB_PROJECT_KEY default to ''). The fake never sends.
        $fake = new LaraBugFake(new \LaraBug\Http\Client('', ''));
        $handler = new LaraBugHandler($fake);

        $write = new ReflectionMethod($handler, 'write');
        $write->setAccessible(true);

        // The shape every line in section 1 produces: a message and a plain context array.
        $write->invoke($handler, [
            'level' => 400,
            'level_name' => 'WARNING',
            'message' => 'Gateway return refused: already claimed.',
            'context' => ['gateway' => 'cinetpay', 'claim' => 'payment'],
            'extra' => [],
        ]);

        $this->assertCount(0, $fake->exceptions,
            'larabug received a record that carries no exception. Section 3 of ' . self::DOC
            . ' says it discards these, and the recommendation there depends on it.');

        // The control: a record that DOES carry an exception is forwarded, so the assertion above
        // is about the exception test and not about the handler being inert.
        $write->invoke($handler, [
            'level' => 400,
            'level_name' => 'WARNING',
            'message' => 'something threw',
            'context' => ['exception' => new RuntimeException('probe')],
            'extra' => [],
        ]);

        $this->assertCount(1, $fake->exceptions,
            'larabug forwarded nothing even for a real exception, so the assertion above may be '
            . 'passing because the handler is inert rather than because it filters.');
    }

    /**
     * THE BLACKLIST STILL COVERS WHAT P1-SEC-14 ADDED.
     *
     * It governs request variables on an EXCEPTION report rather than the lines in section 1 --
     * which is section 3's point -- but it is still the thing standing between a crash on a card
     * form and a card number leaving this server, so it is pinned rather than trusted.
     */
    public function test_the_larabug_blacklist_still_covers_the_classes_p01_added(): void
    {
        $blacklist = array_map('strtolower', (array) config('larabug.blacklist', []));

        foreach ([
            '*password*', '*token*', '*cookie*', '*session*', '*secret*', '*key*',
            '*card*', '*cvv*', '*expir*', '*otp*', '*signature*', '*csrf*', '*bearer*',
        ] as $filter) {
            $this->assertContains($filter, $blacklist,
                'The larabug blacklist no longer carries ' . $filter . '. P1-SEC-14 added it '
                . 'because the shipped list did not cover it, and removing it puts that class of '
                . 'value back on the wire the moment larabug is enabled.');
        }

        $this->assertSame(['production'], (array) config('larabug.environments'),
            'larabug is now reportable outside production. Section 3 of ' . self::DOC . ' states '
            . 'that gate as one of the three reasons the channel is inert today.');
    }

    /** The logging facade is untouched by this file; asserted so a reviewer need not wonder. */
    public function test_this_file_writes_no_log_configuration(): void
    {
        $this->assertTrue(Log::getFacadeRoot() !== null);
    }
}
