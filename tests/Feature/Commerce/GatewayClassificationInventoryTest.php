<?php

namespace Tests\Feature\Commerce;

use App\Payments\PaymentTypeLabel;
use App\Payments\RetiredGateways;
use App\Services\PaymentVerificationService;
use RuntimeException;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * `P04-P5-01` / GAP-136 -- THE GATEWAY CLASSIFICATION TABLE, AND THE CODE IT CLAIMS TO DESCRIBE.
 * =================================================================================================
 *
 * The deliverable is `docs/development/phase-4/PHASE-04-P5-01-GATEWAY-CLASSIFICATION.md`: one row
 * per payment type, saying whether its capture is verified, whether the amount is reconciled, what
 * stops a replay, which test drives it, and -- the distinction the requirement actually turns on --
 * whether a missing verification is EXTERNALLY BLOCKED on a provider credential or is simply absent.
 *
 * A TABLE THAT CAN ROT SILENTLY IS WORTH MUCH LESS THAN THE RATCHET, which is why this file exists
 * and why it is TWO-DIRECTIONAL in every assertion it makes. One direction alone always fails the
 * same way: "every row still exists" lets a new gateway be integrated with no row and nobody
 * noticing, and "every gateway has a row" lets the document accumulate rows for providers that were
 * removed. Both are the same failure -- the document and the code disagree -- so both are failures
 * here. The shape is `SecurityLogInventoryTest` and `RawQueryTenancyInventoryTest`.
 *
 * WHAT IS RE-DERIVED RATHER THAN TRUSTED. Nothing in the document is taken as input to its own
 * check. The payment-type population is re-measured from the four first-party registries; the
 * verification paths are re-censused from `recordVerification()` call sites; the retired set, the
 * amount-enforced set, the offline set and the COD set are read from the constants; every cited
 * `file:line` is re-read; and the derivation the table describes is EXECUTED, not read.
 *
 * ===============================================================================================
 *  EVERY CENSUS HERE IS COMMENT-STRIPPED AND LINE-SCOPED, AND THAT IS LOAD-BEARING.
 * ===============================================================================================
 *
 * `CLAUDE.md` section 3: a census that reads comments can be satisfied -- or broken -- by prose
 * ABOUT the thing it measures, and this codebase has been bitten at least five times. These files
 * are the worst case for it:
 *
 *   - `PaymentVerificationService`'s own comments name `nmi`, `khalti`, `pos`, `cod` and
 *     `recordVerification()` repeatedly while explaining why each is or is not enrolled;
 *   - `PaiementProPaymentController.php:315` contains the literal string `recordVerification()`
 *     inside a 70-line comment explaining that this handler deliberately does NOT call it. A
 *     census that read comments would classify PaiementPro as verified -- and an existing
 *     document in this repository does exactly that, which is recorded in the document's
 *     section 8;
 *   - `AuthorizeNetPaymnetController.php:1183` and `KhaltiPaymnetController.php:1037` both name
 *     `recordVerification()` in prose about where the call must NOT be placed;
 *   - `PaymentController.php:5231` says "no `claimGatewayReturn`, no `Cache::lock`, no
 *     `Cache::add`" -- a sentence that would satisfy a grep for all three.
 *
 * So: `sourceWithoutComments()` everywhere, and every `file:line` assertion reads THAT ONE LINE
 * rather than the file, because an assertion over a whole 5,500-line controller answers "does this
 * string exist anywhere" and a mutation that moves a call survives it.
 *
 * WHY THIS IS A Feature TEST AND NEEDS THE CONTAINER. Two of its assertions execute rather than
 * read: `PaymentSettingController::getPaymentGateways()` is instantiated, and
 * `resolveOrderPaymentStatus()` is called for a representative of every classification term so the
 * table cannot describe a behaviour the service no longer has. The service writes a log line on
 * every branch, which needs a booted application.
 */
class GatewayClassificationInventoryTest extends TestCase
{
    use StripsSourceComments;

    private const DOC = 'docs/development/phase-4/PHASE-04-P5-01-GATEWAY-CLASSIFICATION.md';

    /**
     * The six terms the classification column may hold.
     *
     * `GUARDED, UNVERIFIED` has zero members today and is kept deliberately: the next gateway to
     * gain a verification path without a test must land in it VISIBLY rather than be filed as
     * verified. `NO GATEWAY` is the sixth term the document discloses and justifies -- the five the
     * requirement names are exact for a gateway, and calling cash in a courier's hand "a gateway
     * with no verification path" would make the document's most important number wrong by seven.
     */
    private const TERMS = [
        'VERIFIED',
        'GUARDED, UNVERIFIED',
        'EXTERNALLY BLOCKED',
        'RETIRED',
        'NO VERIFICATION PATH',
        'NO GATEWAY',
    ];

    /** The tally section 4 of the document publishes. Asserted, so the prose cannot drift. */
    private const EXPECTED_TALLY = [
        'NO VERIFICATION PATH' => 44,
        'NO GATEWAY' => 7,
        'VERIFIED' => 3,
        'RETIRED' => 2,
        'EXTERNALLY BLOCKED' => 1,
        'GUARDED, UNVERIFIED' => 0,
    ];

    /** The three terms that REQUIRE a verification path to exist for that payment type. */
    private const TERMS_REQUIRING_A_PATH = ['VERIFIED', 'GUARDED, UNVERIFIED', 'EXTERNALLY BLOCKED'];

    // ---------------------------------------------------------------------------------------
    // The document side
    // ---------------------------------------------------------------------------------------

    /**
     * The table, parsed. Keyed by the folded payment type.
     *
     * @return array<string, array<string, string>>
     */
    private function rowsFromDocument(): array
    {
        $path = base_path(self::DOC);

        if (! is_file($path)) {
            throw new RuntimeException(
                self::DOC . ' is missing. It IS the deliverable of P04-P5-01 / GAP-136; this test '
                . 'has nothing to ratchet without it.'
            );
        }

        $rows = [];

        foreach (file($path) as $line) {
            // `| 7 | `cod` | ... |` -- a numbered row whose second cell is a backticked type.
            if (! preg_match('/^\|\s*(\d+)\s*\|\s*`([^`]+)`\s*\|(.*)\|\s*$/', rtrim($line), $m)) {
                continue;
            }

            $cells = array_map('trim', explode('|', $m[3]));

            if (count($cells) !== 9) {
                throw new RuntimeException(
                    'Row "' . $m[2] . '" of ' . self::DOC . ' has ' . count($cells)
                    . ' cells after the payment type, not 9. The table\'s column count changed, so '
                    . 'every assertion below is reading the wrong column. Fix the row or fix this '
                    . 'parser -- do not let it fall through.'
                );
            }

            $key = strtolower(trim($m[2]));

            if (isset($rows[$key])) {
                throw new RuntimeException($key . ' appears twice in ' . self::DOC . '.');
            }

            $rows[$key] = [
                'n' => $m[1],
                'kind' => $cells[0],
                'class' => $cells[1],
                'classification' => $cells[2],
                'decides' => trim($cells[3], '` '),
                'amount' => $cells[4],
                'replay' => trim($cells[5], '` '),
                'own_check' => $cells[6],
                'test' => $cells[7],
                'blocker' => $cells[8],
            ];
        }

        ksort($rows);

        return $rows;
    }

    // ---------------------------------------------------------------------------------------
    // The code side
    // ---------------------------------------------------------------------------------------

    /**
     * Every payment-type identifier the four first-party registries know, folded.
     *
     * Each registry is read COMMENT-STRIPPED and SCOPED TO ITS OWN METHOD BODY, never to the file.
     * `getValidPaymentTypes()` is a bare list of quoted strings, so an unscoped pattern over
     * `PaymentController.php` would collect several thousand unrelated string literals; and the
     * whitespace in `$Setting_array[ 41 ][ 'name' ]` is not cosmetic -- nine of `paymentList()`'s
     * assignments are written that way and a census that missed them already published two wrong
     * numbers in this repository (`PaymentTypeLabel`'s docblock records it).
     *
     * @return array<string, list<string>>  folded type => the spellings found
     */
    private function typesFromRegistries(): array
    {
        $found = [];

        $add = function (array $spellings) use (&$found): void {
            foreach ($spellings as $spelling) {
                $key = strtolower(trim($spelling));
                $found[$key][] = $spelling;
            }
        };

        $add($this->matchesIn(
            $this->methodBody('app/Http/Controllers/PaymentSettingController.php', 'getPaymentGateways'),
            "/'id'\s*=>\s*'([^']+)'/"
        ));

        $add($this->matchesIn(
            $this->methodBody('app/Models/Setting.php', 'paymentList'),
            "/\\\$Setting_array\s*\[\s*\d+\s*\]\s*\[\s*'name'\s*\]\s*=\s*'([^']+)'/"
        ));

        $add($this->matchesIn(
            $this->methodBody('app/Http/Controllers/PaymentController.php', 'getValidPaymentTypes'),
            "/'([^']+)'/"
        ));

        $add($this->matchesIn(
            $this->methodBody('app/Services/PaymentService.php', 'process'),
            "/case\s+'([^']+)'\s*:/"
        ));

        // The two values live code stores and no registry offers. PosController writes 'POS' as a
        // literal; the lottery one is written by a VENDORED module that may not be edited.
        $add(['POS', 'Lottery Product']);

        ksort($found);

        return $found;
    }

    /**
     * One method's body, comment-stripped.
     *
     * The body ends at the next declaration at method indentation. `PaymentService::process()` is
     * followed by three blank lines and then a `private function`, and `paymentList()` by a
     * `public static function`, so both forms have to be recognised.
     */
    private function methodBody(string $relativePath, string $method): string
    {
        $lines = explode("\n", $this->sourceWithoutComments($relativePath));

        $start = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $line)) {
                $start = $i;
                break;
            }
        }

        $this->assertNotNull($start, $relativePath . ' no longer declares ' . $method . '().');

        $end = count($lines);

        for ($j = $start + 1; $j < count($lines); $j++) {
            if (preg_match('/^\s{0,8}(?:public|private|protected)\s+(?:static\s+)?function\s/', $lines[$j])) {
                $end = $j;
                break;
            }
        }

        $body = implode("\n", array_slice($lines, $start, $end - $start));

        $this->assertNotSame('', trim($body), $relativePath . '::' . $method . ' read as empty.');

        return $body;
    }

    /** @return list<string> */
    private function matchesIn(string $haystack, string $pattern): array
    {
        preg_match_all($pattern, $haystack, $m);

        $hits = $m[1] ?? [];

        $this->assertNotEmpty($hits,
            'A registry pattern matched nothing: ' . $pattern . '. The pattern, not the codebase, '
            . 'is the likely cause -- and a census that matches nothing makes every set comparison '
            . 'below trivially true.');

        return $hits;
    }

    /**
     * Every `recordVerification()` CALL in `app/`, comment-stripped, with the payment type it names.
     *
     * The declaration itself is excluded by name. The first argument can sit on the following line,
     * so a small window is joined -- bounded at four lines so it cannot run into the next statement.
     *
     * @return array<string, list<string>>  folded type => "RelativePath.php:line"
     */
    private function verificationPathsFromSource(): array
    {
        $found = [];

        foreach ($this->phpFilesUnder('app') as $relative) {
            $lines = explode("\n", $this->sourceWithoutComments($relative));

            foreach ($lines as $i => $line) {
                if (! str_contains($line, 'recordVerification(')) {
                    continue;
                }

                if (str_contains($line, 'function recordVerification')) {
                    continue;
                }

                $window = implode("\n", array_slice($lines, $i, 4));

                if (! preg_match("/recordVerification\(\s*'([^']*)'/s", $window, $m)) {
                    throw new RuntimeException(
                        $relative . ':' . ($i + 1) . ' calls recordVerification() with a payment '
                        . 'type this census cannot read (not a literal string within four lines). '
                        . 'It cannot be classified, so it must not be skipped: either make the '
                        . 'argument a literal or teach this census how to resolve it.'
                    );
                }

                $found[strtolower(trim($m[1]))][] = $relative . ':' . ($i + 1);
            }
        }

        ksort($found);

        return $found;
    }

    /** @return list<string> relative paths */
    private function phpFilesUnder(string $dir): array
    {
        $root = base_path();
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Resolve a citation like `PaymentController.php:3427` or `Models/Setting.php:124` to one file.
     *
     * A suffix match under `app/` that must be UNIQUE. `Setting.php` alone is ambiguous
     * (`app/Models/Setting.php` and `app/Classes/Setting.php`), so an ambiguous citation fails
     * rather than silently picking one -- a reader sent to the wrong file is the failure this whole
     * file exists to prevent.
     */
    /**
     * A citation cell into one or more single citations.
     *
     * `paymera` has TWO verification sites (the callback and the trigger), written
     * `PaymentController.php:733, :1689` -- the second inherits the first's file. Both are
     * resolved and both are checked, rather than the cell being narrowed to one so the parser has
     * an easier time: the trigger site is the one a machine caller reaches, and a table that cited
     * only the browser-callback site would describe half the path.
     *
     * @return list<string>
     */
    private function expandCitations(string $cell): array
    {
        $parts = array_map('trim', explode(',', $cell));
        $file = null;
        $out = [];

        foreach ($parts as $part) {
            if (preg_match('/^([A-Za-z0-9_\/]+\.php):(\d+)$/', $part, $m)) {
                $file = $m[1];
                $out[] = $m[1] . ':' . $m[2];

                continue;
            }

            if (preg_match('/^:(\d+)$/', $part, $m)) {
                $this->assertNotNull($file,
                    'Citation cell "' . $cell . '" begins with a bare line number, so there is no '
                    . 'file for it to belong to.');

                $out[] = $file . ':' . $m[1];

                continue;
            }

            $this->fail('Citation cell "' . $cell . '" holds "' . $part
                . '", which is neither <path>.php:<line> nor a bare :<line> continuation.');
        }

        return $out;
    }

    private function resolveCitation(string $citation): array
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\/]+\.php:\d+$/', $citation,
            'Citation "' . $citation . '" in ' . self::DOC . ' is not of the form <path>.php:<line>.');

        [$file, $line] = explode(':', $citation);

        $matches = array_values(array_filter(
            $this->phpFilesUnder('app'),
            static fn (string $candidate): bool => str_ends_with($candidate, '/' . $file)
        ));

        $this->assertCount(1, $matches,
            'Citation "' . $citation . '" resolves to ' . count($matches) . ' files under app/: '
            . implode(', ', $matches) . '. Spell more of the path in the document.');

        $source = explode("\n", $this->sourceWithoutComments($matches[0]));
        $index = (int) $line - 1;

        $this->assertArrayHasKey($index, $source,
            'Citation "' . $citation . '" points past the end of ' . $matches[0] . '.');

        return [$matches[0], $source[$index]];
    }

    private function gatewayRows(array $rows): array
    {
        return array_filter($rows, static fn (array $r): bool => $r['kind'] === 'gateway');
    }

    // =======================================================================================
    // 0. PREMISE
    // =======================================================================================

    /**
     * THE CENSUSES FOUND SOMETHING, so nothing below is vacuously true.
     *
     * Every set comparison in this file is satisfied by two empty sets. This project has recorded
     * that exact false reading more than once, so the floors are asserted first and separately.
     */
    public function test_the_censuses_are_not_vacuous(): void
    {
        $rows = $this->rowsFromDocument();
        $types = $this->typesFromRegistries();
        $paths = $this->verificationPathsFromSource();

        $this->assertGreaterThan(50, count($rows),
            self::DOC . ' parsed ' . count($rows) . ' rows. The parser, not the document, is the '
            . 'likely cause -- and with no rows every comparison below passes for free.');

        $this->assertGreaterThan(50, count($types),
            'The registry census found only ' . count($types) . ' payment types.');

        $this->assertNotEmpty($paths,
            'No recordVerification() call site was found in app/ at all. Either every verification '
            . 'path was deleted -- which is a far larger event than this test -- or the census is '
            . 'broken. Both must fail here.');
    }

    // =======================================================================================
    // 1. THE ROW SET, BOTH DIRECTIONS, AGAINST TWO INDEPENDENT CODE SOURCES
    // =======================================================================================

    /**
     * THE DOCUMENT LISTS EXACTLY THE PAYMENT TYPES THE CODE KNOWS -- AND SO DOES THE LABEL MAP.
     *
     * Three enumerations, pairwise set-differenced in both directions:
     *
     *   1. the four first-party registries (what the admin screen offers, what the storefront
     *      lists, what the checkout accepts, what the dispatch handles) plus the two stored
     *      literals;
     *   2. `PaymentTypeLabel::known()`, which is maintained SEPARATELY and by hand for F150;
     *   3. this document's table.
     *
     * (2) is not redundant. It is the independent confirmation that the population is complete:
     * two separately-maintained enumerations of the same vocabulary agreeing exactly is much
     * stronger evidence than either alone, and if they ever diverge, the divergence is itself the
     * finding -- a payment type that can be stored but cannot be labelled, or offered but not
     * stored.
     */
    public function test_the_document_lists_exactly_the_payment_types_the_code_knows(): void
    {
        $registries = array_keys($this->typesFromRegistries());
        $labels = array_map(
            static fn ($t) => strtolower(trim((string) $t)),
            PaymentTypeLabel::known()
        );
        $document = array_keys($this->rowsFromDocument());

        sort($registries);
        sort($labels);
        sort($document);

        $this->assertSame([], array_values(array_diff($registries, $labels)),
            'These payment types are in the registries and NOT in PaymentTypeLabel::known(), so '
            . 'they would render a bare dash in the order list: '
            . implode(', ', array_diff($registries, $labels)));

        $this->assertSame([], array_values(array_diff($labels, $registries)),
            'PaymentTypeLabel::known() carries these types and no registry offers them: '
            . implode(', ', array_diff($labels, $registries)));

        $this->assertSame([], array_values(array_diff($registries, $document)),
            "These payment types exist in the code and have NO ROW in " . self::DOC . ". A gateway "
            . "with no row is a gateway whose verification status nobody has stated:\n  "
            . implode("\n  ", array_diff($registries, $document))
            . "\n\nAdd a row. Its classification must be one of: " . implode(' | ', self::TERMS)
            . "\nand the per-term rules this file asserts are in section 2.4 of the document.");

        $this->assertSame([], array_values(array_diff($document, $registries)),
            "These rows are in " . self::DOC . " and name a payment type no registry knows. A "
            . "classification table that lists providers which no longer exist is not a "
            . "classification table:\n  " . implode("\n  ", array_diff($document, $registries)));
    }

    /** THE PUBLISHED COUNTS ARE THE ROWS' COUNTS. */
    public function test_the_published_counts_match_the_rows(): void
    {
        $rows = $this->rowsFromDocument();

        $this->assertCount(57, $rows,
            'The document publishes 57 payment-type identifiers in section 1. It now has '
            . count($rows) . ' rows. Section 1, the section-4 tally and the GAP-136 register row '
            . 'all carry that figure and all three have to move together.');

        $this->assertCount(50, $this->gatewayRows($rows),
            'The document publishes 50 gateway identifiers (48 providers plus the two spelled '
            . 'twice). It now has ' . count($this->gatewayRows($rows)) . '.');
    }

    // =======================================================================================
    // 2. THE TERMS AND THE TALLY
    // =======================================================================================

    public function test_every_classification_is_one_of_the_declared_terms_and_the_tally_matches(): void
    {
        $rows = $this->rowsFromDocument();
        $tally = array_fill_keys(self::TERMS, 0);

        foreach ($rows as $type => $row) {
            $this->assertContains($row['classification'], self::TERMS,
                $type . ' is classified "' . $row['classification'] . '", which is not one of the '
                . 'declared terms: ' . implode(' | ', self::TERMS) . '. A new term needs a '
                . 'definition in section 2.4 and a rule in this file, or it means nothing.');

            $tally[$row['classification']]++;
        }

        $expected = self::EXPECTED_TALLY;

        // Sorted before comparison: assertSame on arrays compares key ORDER, and the order a
        // constant happens to be written in is not part of the claim.
        ksort($expected);
        ksort($tally);

        $this->assertSame($expected, array_intersect_key($tally, $expected),
            "The classification tally has changed. Section 4 of " . self::DOC . " publishes it and "
            . "section 1's '44 of 50 gateways have no verification path' is the register's own "
            . "figure, so the document and the GAP-136 row both have to be updated.\n"
            . 'measured: ' . json_encode($tally));

        /*
         * GUARDED, UNVERIFIED IS ZERO, AND ASSERTED SEPARATELY SO IT CANNOT BE EXPLAINED AWAY.
         *
         * A term with no members looks like dead vocabulary. It is not: it is the bucket the NEXT
         * gateway to gain a verification path without a test must fall into. If this ever becomes
         * non-zero the tally above fails and a reader is forced to look, which is the point.
         */
        $this->assertSame(0, $tally['GUARDED, UNVERIFIED'],
            'A row is now GUARDED, UNVERIFIED: a verification path with no test and no external '
            . 'blocker. That is a test somebody can write today. Write it, move the row to '
            . 'VERIFIED, and update section 2.4.');
    }

    // =======================================================================================
    // 3. THE SETS THAT COME FROM CONSTANTS -- ALL BOTH-WAYS
    // =======================================================================================

    public function test_the_retired_rows_are_exactly_the_retired_gateways(): void
    {
        $documented = array_keys(array_filter(
            $this->rowsFromDocument(),
            static fn (array $r): bool => $r['classification'] === 'RETIRED'
        ));

        $code = array_map('strtolower', RetiredGateways::all());

        sort($documented);
        sort($code);

        $this->assertSame($code, $documented,
            'RetiredGateways::PROVIDERS and the RETIRED rows of ' . self::DOC . ' disagree. '
            . 'Retiring a third provider is one line in that class and one row here; doing the '
            . 'first without the second leaves the table saying a withdrawn provider is live. '
            . 'code: ' . implode(',', $code) . ' / document: ' . implode(',', $documented));
    }

    public function test_the_amount_reconciled_rows_are_exactly_the_enforced_payment_types(): void
    {
        $rows = $this->rowsFromDocument();

        $documented = array_keys(array_filter(
            $rows,
            static fn (array $r): bool => $r['amount'] === 'yes'
        ));

        $code = array_map('strtolower', PaymentVerificationService::ENFORCED_PAYMENT_TYPES);

        sort($documented);
        sort($code);

        $this->assertSame($code, $documented,
            'ENFORCED_PAYMENT_TYPES and the "amount reconciled = yes" rows disagree. The constant '
            . 'is what makes the captured amount comparable against the order total, so a row '
            . 'claiming it without membership is the exact overstatement GAP-136 forbids -- and a '
            . 'member with no row hides a tightening. '
            . 'code: ' . implode(',', $code) . ' / document: ' . implode(',', $documented));

        // And the other cells may only be 'yes' or 'no', so a typo cannot read as 'no'.
        foreach ($rows as $type => $row) {
            $this->assertContains($row['amount'], ['yes', 'no'],
                $type . '\'s amount cell is "' . $row['amount'] . '"; it must be yes or no.');
        }
    }

    public function test_the_offline_and_cod_rows_are_exactly_the_constants(): void
    {
        $rows = $this->rowsFromDocument();

        /*
         * OFFLINE_PAYMENT_TYPES is what resolves to Paid with no gateway involved, so every member
         * must be a NO GATEWAY row and no gateway row may cite the offline branch. This is the
         * assertion that would have caught the telegram/whatsapp asymmetry the document records in
         * section 8: `whatsapp` is a member and `telegram` is not, although the two are written by
         * the same writer.
         */
        $offlineBranch = array_keys(array_filter(
            $rows,
            static fn (array $r): bool => $r['decides'] === 'PaymentVerificationService.php:303'
        ));

        $code = array_map('strtolower', PaymentVerificationService::OFFLINE_PAYMENT_TYPES);

        sort($offlineBranch);
        sort($code);

        $this->assertSame($code, $offlineBranch,
            'OFFLINE_PAYMENT_TYPES and the rows whose classification is decided by the offline '
            . 'branch disagree. A type joining or leaving that constant changes whether its orders '
            . 'are born Paid, which is a business change and must not be invisible. '
            . 'code: ' . implode(',', $code) . ' / document: ' . implode(',', $offlineBranch));

        $codBranch = array_keys(array_filter(
            $rows,
            static fn (array $r): bool => $r['decides'] === 'PaymentVerificationService.php:265'
        ));

        $this->assertSame(['cod'], $codBranch,
            'Exactly one row -- `cod` -- is decided by the COD branch. COD_PAYMENT_TYPES also '
            . 'holds the aliases cash_on_delivery and cash, which no registry offers as a '
            . 'checkout choice and which therefore get no row. found: ' . implode(',', $codBranch));

        foreach (['cod', 'cash_on_delivery', 'cash'] as $alias) {
            $this->assertContains($alias, PaymentVerificationService::COD_PAYMENT_TYPES,
                'COD_PAYMENT_TYPES no longer holds ' . $alias . '; the row above describes a '
                . 'constant that has changed.');
        }

        foreach ($offlineBranch as $type) {
            $this->assertSame('NO GATEWAY', $rows[$type]['classification'],
                $type . ' settles off-gateway by arrangement but is classified as a gateway.');
        }
    }

    // =======================================================================================
    // 4. THE CLASSIFICATION AGREES WITH THE VERIFICATION-PATH CENSUS, BOTH DIRECTIONS
    // =======================================================================================

    /**
     * A VERIFICATION PATH EXISTS FOR EXACTLY THE ROWS THAT CLAIM ONE.
     *
     * This is the assertion GAP-136 is really about. "None marked verified without evidence" is
     * enforced here: a row may only claim VERIFIED, GUARDED-UNVERIFIED or EXTERNALLY BLOCKED if
     * `app/` really contains a `recordVerification()` call naming its payment type -- and a row
     * claiming NO VERIFICATION PATH fails the moment one appears, so a gateway that gains a
     * verification cannot keep being described as unverified.
     */
    public function test_a_verification_path_exists_for_exactly_the_rows_that_claim_one(): void
    {
        $rows = $this->rowsFromDocument();
        $paths = $this->verificationPathsFromSource();

        $claimed = [];

        foreach ($rows as $type => $row) {
            $hasPath = isset($paths[$type]);
            $claimsPath = in_array($row['classification'], self::TERMS_REQUIRING_A_PATH, true);

            if ($claimsPath) {
                $claimed[] = $type;
            }

            if ($claimsPath && ! $hasPath) {
                $this->fail(
                    $type . ' is classified ' . $row['classification'] . ', which asserts that a '
                    . 'capture-verification path exists -- but no recordVerification() call in '
                    . 'app/ names it. Either the call was removed (and this row is now a false '
                    . 'claim of verification) or the row is wrong. Call sites found: '
                    . implode('; ', array_keys($paths))
                );
            }

            if (! $claimsPath && $hasPath) {
                $this->fail(
                    $type . ' is classified ' . $row['classification'] . ', but app/ now records a '
                    . 'verification for it at ' . implode(', ', $paths[$type]) . '. That is an '
                    . 'IMPROVEMENT and this failure is how the table gets updated to say so: move '
                    . 'the row to VERIFIED if a test drives that path to a Paid order, to '
                    . 'EXTERNALLY BLOCKED if driving it needs a provider credential, or to '
                    . 'GUARDED, UNVERIFIED otherwise.'
                );
            }
        }

        sort($claimed);

        $this->assertSame(array_keys($paths), $claimed,
            'The set of payment types with a recordVerification() call and the set of rows '
            . 'claiming a verification path differ. code: ' . implode(',', array_keys($paths))
            . ' / document: ' . implode(',', $claimed));

        // The document's section 2.3 publishes the call-site count. Five sites, four types --
        // Paymera has two. The distinction matters: an existing document in this repository reads
        // the fifth site as a fifth gateway (PaiementPro) and is wrong, which section 8 records.
        $siteCount = array_sum(array_map('count', $paths));

        $this->assertSame(5, $siteCount,
            'Section 2.3 of ' . self::DOC . ' publishes five recordVerification() call sites '
            . 'naming four payment types. There are now ' . $siteCount . '.');
    }

    /**
     * PAIEMENTPRO RECORDS NOTHING, AND THIS IS THE COMMENT-TRAP CONTROL.
     *
     * `PaiementProPaymentController` contains the literal `recordVerification()` inside a long
     * comment explaining that it deliberately does not call it, and an existing document in this
     * repository classifies PaiementPro as one of the five verifying paths on that basis. This
     * assertion is what proves the census above reads CODE: if the stripper ever stops working,
     * PaiementPro appears in the path census, the test above fails, and this one says why.
     */
    public function test_the_verification_census_is_not_satisfied_by_prose_about_it(): void
    {
        $paths = $this->verificationPathsFromSource();

        $this->assertArrayNotHasKey('paiementpro', $paths,
            'The verification census now reports a path for paiementpro. Before changing the '
            . 'table, check whether the handler really calls recordVerification() or whether the '
            . 'comment at PaiementProPaymentController.php:315 -- which names the function while '
            . 'explaining that it is deliberately NOT called -- has started satisfying the census. '
            . 'The handler logs "this gateway is not provider-verified" on every return.');

        $raw = (string) file_get_contents(base_path('app/Http/Controllers/PaiementProPaymentController.php'));

        $this->assertStringContainsString('recordVerification()', $raw,
            'The prose that makes the control above meaningful is gone from '
            . 'PaiementProPaymentController. If the comment was removed, this test no longer '
            . 'proves the stripper works and a different file must be chosen as the control.');

        $this->assertStringNotContainsString(
            'recordVerification(',
            $this->sourceWithoutComments('app/Http/Controllers/PaiementProPaymentController.php'),
            'PaiementPro now calls recordVerification() in code. If that is real, it is a new '
            . 'verification path and the table must be updated; if it is not, the comment stripper '
            . 'has stopped working and every census in this file is unreliable.'
        );
    }

    // =======================================================================================
    // 5. EVERY CITED LINE STILL HOLDS WHAT THE ROW SAYS IT HOLDS
    // =======================================================================================

    /**
     * THE `what decides it` CITATIONS ARE RE-READ, ONE LINE AT A TIME.
     *
     * Line-number citations drift whenever a comment above them is edited, and that is ACCEPTED
     * here for the same reason `SecurityLogInventoryTest` accepts it: the thing being ratcheted is
     * a DOCUMENT that has to be regenerated when the code moves, so a drift failing this test is
     * the correct outcome. The failure names the line and what was expected there, so a reader can
     * re-point it rather than re-derive the table.
     */
    public function test_every_cited_deciding_line_still_holds_what_the_row_claims(): void
    {
        $rows = $this->rowsFromDocument();
        $checked = 0;

        foreach ($rows as $type => $row) {
            if ($row['decides'] === '—' || $row['decides'] === '') {
                $this->fail($type . ' cites nothing in its "what decides it" column.');
            }

            foreach ($this->expandCitations($row['decides']) as $citation) {
            [$file, $line] = $this->resolveCitation($citation);
            $checked++;

            $expectation = match (true) {
                in_array($row['classification'], self::TERMS_REQUIRING_A_PATH, true)
                    => ['recordVerification(', 'a recordVerification() call'],
                $row['classification'] === 'RETIRED'
                    => ['const PROVIDERS', "RetiredGateways' own list"],
                $citation === 'PaymentVerificationService.php:339'
                    => ['$verification === null', 'the central no-verification-record branch'],
                $citation === 'PaymentVerificationService.php:303'
                    => ['OFFLINE_PAYMENT_TYPES', 'the offline-types branch'],
                $citation === 'PaymentVerificationService.php:265'
                    => ['isCashOnDelivery', 'the COD branch'],
                $citation === 'Models/Setting.php:124'
                    => ["'off'", "paymentList()'s hard-coded off status"],
                default => [null, null],
            };

            $this->assertNotNull($expectation[0],
                $type . ' cites ' . $citation . ', which this test does not know how to verify. '
                . 'Every citation in the table must be checkable; add a rule here or cite a line '
                . 'that is already covered.');

            $this->assertStringContainsString($expectation[0], $line,
                $type . ' cites ' . $citation . ' as ' . $expectation[1] . ', and that line of '
                . $file . ' does not contain "' . $expectation[0] . '". Comment-stripped, the line '
                . 'reads: ' . trim($line));

            // For the four with a path, the cited line must name THIS payment type, not another's.
            if (in_array($row['classification'], self::TERMS_REQUIRING_A_PATH, true)) {
                $window = $this->citationWindow($citation);

                $this->assertMatchesRegularExpression(
                    "/recordVerification\(\s*'" . preg_quote($type, '/') . "'/i",
                    $window,
                    $type . ' cites ' . $citation . ' as its verification path, but that call names '
                    . 'a different payment type. A row pointing at another gateway\'s verification '
                    . 'is the exact "marked verified without evidence" failure GAP-136 names.'
                );
            }
            }
        }

        $this->assertGreaterThanOrEqual(count($rows), $checked,
            'Only ' . $checked . ' citations were checked across ' . count($rows) . ' rows, so '
            . 'this assertion is partial.');
    }

    /** Four lines from a citation, comment-stripped: a call's arguments may wrap. */
    private function citationWindow(string $citation): string
    {
        [$file, $line] = explode(':', $citation);

        $matches = array_values(array_filter(
            $this->phpFilesUnder('app'),
            static fn (string $candidate): bool => str_ends_with($candidate, '/' . $file)
        ));

        $source = explode("\n", $this->sourceWithoutComments($matches[0]));

        return implode("\n", array_slice($source, (int) $line - 1, 4));
    }

    /**
     * THE `replay claim` CITATIONS ARE RE-READ TOO.
     *
     * Three shapes, and the shape is part of the claim: the seven storefront order writers take a
     * `claimGatewayReturn()` keyed on the checkout nonce; `cod`/`bank_transfer` are claimed on the
     * way OUT of the checkout by `processOrder`'s basket-fingerprint `Cache::add` (the fix for
     * P4-F018) because there is no gateway return to claim; and the relay writer takes a
     * `Cache::lock`. Asserting the shape is what stops "it has a replay guard" being true of a row
     * whose guard is somewhere else entirely.
     */
    public function test_every_cited_replay_claim_still_exists_in_that_shape(): void
    {
        $rows = $this->rowsFromDocument();
        $checked = 0;

        foreach ($rows as $type => $row) {
            if ($row['replay'] === '—') {
                $this->assertSame('NO GATEWAY', $row['classification'],
                    $type . ' cites no replay claim but is classified as a gateway. Every gateway '
                    . 'return in this codebase takes one.');

                continue;
            }

            [$file, $line] = $this->resolveCitation($row['replay']);
            $checked++;

            $needle = match (true) {
                str_contains($line, 'claimGatewayReturn') => 'claimGatewayReturn',
                str_contains($line, 'Cache::add') => 'Cache::add',
                str_contains($line, 'Cache::lock') => 'Cache::lock',
                default => null,
            };

            $this->assertNotNull($needle,
                $type . ' cites ' . $row['replay'] . ' as its replay claim, and that line of '
                . $file . ' holds no claim construct at all. Comment-stripped it reads: '
                . trim($line) . ' -- note that PaymentController.php:5231 is PROSE saying "no '
                . 'claimGatewayReturn, no Cache::lock, no Cache::add", so a citation that drifted '
                . 'onto it would have satisfied a census that read comments.');

            // Class A and B share ONE writer, so their claim must be the generic one by label.
            if (in_array($row['class'], ['A', 'B'], true)) {
                $this->assertStringContainsString("'storefront-return'", $line,
                    $type . ' is class ' . $row['class'] . ', so its order is written by the '
                    . 'generic storefront return and its claim is that writer\'s '
                    . "claimGatewayReturn(\$request, 'storefront-return', ...). The cited line is: "
                    . trim($line));
            }
        }

        $this->assertGreaterThan(45, $checked,
            'Only ' . $checked . ' replay citations were checked; the table has ' . count($rows)
            . ' rows and all but the three non-selectable ones cite a claim.');
    }

    // =======================================================================================
    // 6. THE NAMED TESTS EXIST
    // =======================================================================================

    /**
     * EVERY TEST THE TABLE NAMES AS ITS EVIDENCE EXISTS ON DISK.
     *
     * "None marked verified without evidence" needs the evidence to be findable. A row naming a
     * test file that was renamed or deleted is a row whose evidence has evaporated, and that reads
     * exactly like a row whose evidence holds.
     */
    public function test_every_test_named_in_the_table_exists(): void
    {
        $rows = $this->rowsFromDocument();

        $files = [];

        foreach ($this->phpFilesUnder('tests') as $relative) {
            $files[basename($relative, '.php')] = $relative;
        }

        $this->assertGreaterThan(200, count($files), 'The test-file census found almost nothing.');

        $named = 0;

        foreach ($rows as $type => $row) {
            if ($row['test'] === '—') {
                continue;
            }

            // "KhaltiAmountBindingTest:207 + GatewayStockBeforeVerificationTest:371"
            preg_match_all('/([A-Za-z0-9_]*Test)\b/', $row['test'], $m);

            $this->assertNotEmpty($m[1],
                $type . '\'s test cell is "' . $row['test'] . '" and names no *Test class.');

            foreach ($m[1] as $class) {
                $named++;

                $this->assertArrayHasKey($class, $files,
                    $type . ' names ' . $class . ' as its evidence and no such test file exists '
                    . 'under tests/. A row whose evidence has been renamed or deleted is a row '
                    . 'with no evidence, and it reads exactly like one that has some.');
            }
        }

        $this->assertGreaterThan(55, $named,
            'Only ' . $named . ' test references were checked across ' . count($rows) . ' rows.');
    }

    // =======================================================================================
    // 7. THE DERIVATION IS EXECUTED, NOT READ
    // =======================================================================================

    /**
     * THE SERVICE REALLY BEHAVES THE WAY THE TABLE DESCRIBES.
     *
     * Everything above compares a document with SOURCE. This compares it with BEHAVIOUR: one
     * representative per classification term is driven through `resolveOrderPaymentStatus()` and
     * the resulting status asserted. Without this the table could describe a derivation the service
     * no longer performs and every structural assertion would still pass.
     *
     * `telegram` IS DELIBERATELY HERE, and this is the only test in the repository that pins it.
     * Section 8 of the document records why that matters: `whatsapp` is in OFFLINE_PAYMENT_TYPES
     * and `telegram` is not, although both are relays with no payment API written by the same
     * writer, so Telegram orders are born Pending and WhatsApp orders are born Paid. That is a
     * business decision for the owner, not something to fix from a test -- but it must not be
     * invisible, and an unpinned asymmetry is invisible.
     */
    public function test_the_derivation_the_table_describes_is_what_the_service_does(): void
    {
        $verifier = new PaymentVerificationService();

        $expected = [
            // NO VERIFICATION PATH -- the 44. No record, so Pending.
            'stripe' => PaymentVerificationService::STATUS_PENDING,
            'cybersource' => PaymentVerificationService::STATUS_PENDING,
            'payu' => PaymentVerificationService::STATUS_PENDING,
            // RETIRED -- still derives Pending; being refused at the offer is the other control.
            'payhere' => PaymentVerificationService::STATUS_PENDING,
            // EXTERNALLY BLOCKED -- the path exists but nothing recorded here, so Pending.
            'authorizenet' => PaymentVerificationService::STATUS_PENDING,
            // VERIFIED -- the record is what makes these Paid; with none they are Pending too.
            'paymera' => PaymentVerificationService::STATUS_PENDING,
            'nmi' => PaymentVerificationService::STATUS_PENDING,
            'khalti' => PaymentVerificationService::STATUS_PENDING,
            // NO GATEWAY -- the off-gateway arrangements.
            'bank_transfer' => PaymentVerificationService::STATUS_PAID,
            'whatsapp' => PaymentVerificationService::STATUS_PAID,
            'pos' => PaymentVerificationService::STATUS_PAID,
            'lottery product' => PaymentVerificationService::STATUS_PAID,
            // NO GATEWAY -- COD has its own state machine and is Pending until collection.
            'cod' => PaymentVerificationService::STATUS_PENDING,
            // NO GATEWAY -- the asymmetry section 8 item 1 records.
            'telegram' => PaymentVerificationService::STATUS_PENDING,
        ];

        foreach ($expected as $type => $status) {
            $this->assertSame($status, $verifier->resolveOrderPaymentStatus(
                $type,
                null,
                '100.00',
                __METHOD__
            ), $type . ' no longer derives ' . $status . ' with no verification record. The table '
                . 'in ' . self::DOC . ' describes the derivation as it was measured; this is a '
                . 'behaviour change and the document has to be re-measured.');
        }

        /*
         * AND THE CONTRAST THAT STOPS THE ABOVE BEING VACUOUS. If the service returned Pending for
         * everything, eleven of the fourteen assertions would pass for the wrong reason. A matching
         * record on an amount-enforced type really does produce Paid.
         */
        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $verifier->resolveOrderPaymentStatus(
                'paymera',
                ['payment_type' => 'paymera', 'expected_amount' => '100.00'],
                '100.00',
                __METHOD__
            ),
            'A matching verification record no longer yields Paid, so the Pending assertions above '
            . 'prove nothing about the derivation.'
        );
    }

    // =======================================================================================
    // 8. THE NON-SELECTABLE ROWS ARE REALLY NOT SELECTABLE
    // =======================================================================================

    /**
     * `other_payment`, `pos` AND `lottery product` CANNOT BE CHOSEN AT A CHECKOUT.
     *
     * The table classifies them NO GATEWAY on that basis, so the basis is asserted rather than
     * stated. `other_payment` is offered in the storefront list with a hard-coded `off` status and
     * is refused by `getValidPaymentTypes()`; the other two are written by code (one of it
     * vendored) and appear in no registry a checkout reads.
     */
    public function test_the_not_selectable_rows_are_really_not_selectable(): void
    {
        $accepted = array_map(
            'strtolower',
            $this->matchesIn(
                $this->methodBody('app/Http/Controllers/PaymentController.php', 'getValidPaymentTypes'),
                "/'([^']+)'/"
            )
        );

        $dispatched = array_map(
            'strtolower',
            $this->matchesIn(
                $this->methodBody('app/Services/PaymentService.php', 'process'),
                "/case\s+'([^']+)'\s*:/"
            )
        );

        $this->assertContains('stripe', $accepted,
            'The accepted-types census does not even contain stripe, so the refusals below are '
            . 'vacuous.');

        foreach (['other_payment', 'pos', 'lottery product'] as $type) {
            $this->assertNotContains($type, $accepted,
                $type . ' is now an accepted checkout payment type. The table classifies it as not '
                . 'selectable, and a shopper being able to choose it changes that.');

            $this->assertNotContains($type, $dispatched,
                $type . ' now has a dispatch arm in PaymentService::process().');
        }

        $rows = $this->rowsFromDocument();

        foreach (['other_payment', 'pos', 'lottery product'] as $type) {
            $this->assertSame('—', $rows[$type]['class'],
                $type . ' has been given a return class; it has no gateway return.');
        }
    }

    /**
     * EVERY SETTINGS ID A BLOCKER NAMES IS A REAL ONE.
     *
     * The blocker column is the half of the table that answers GAP-136's actual instruction --
     * "marking credential-dependent gateways EXTERNALLY BLOCKED rather than untested" -- so where a
     * row says which gateway's credentials would be needed, it must name the `id` the settings
     * screen really uses. Those ids are not the folded payment type: `dpo` is stored under
     * `dpopay`, `senagepay` under `Senangpay`, `paymera` under `Paymera`, `xendit` under `Xendit`.
     * A row naming a non-existent id sends somebody looking for a settings block that is not there.
     */
    public function test_every_settings_id_a_blocker_names_really_exists(): void
    {
        $ids = array_map(
            'strtolower',
            $this->matchesIn(
                $this->methodBody('app/Http/Controllers/PaymentSettingController.php', 'getPaymentGateways'),
                "/'id'\s*=>\s*'([^']+)'/"
            )
        );

        $named = 0;

        foreach ($this->rowsFromDocument() as $type => $row) {
            if (! preg_match('/`([^`]+)` keys/', $row['blocker'], $m)) {
                continue;
            }

            $named++;

            $this->assertContains(strtolower($m[1]), $ids,
                $type . '\'s blocker names `' . $m[1] . '` as its settings id and '
                . 'getPaymentGateways() has no such id. The ids are not the folded payment type -- '
                . 'dpo is stored under dpopay and senagepay under Senangpay -- so this is how a '
                . 'reader gets sent to a settings block that does not exist. Ids: '
                . implode(', ', $ids));
        }

        $this->assertGreaterThan(30, $named,
            'Only ' . $named . ' rows name a settings id; section 5 of ' . self::DOC . ' publishes '
            . '37 rows whose blocker is a provider verify endpoint plus that gateway\'s keys.');
    }

    // =======================================================================================
    // 9. THIS FILE CHANGES NOTHING
    // =======================================================================================

    /** The inventory is read-only: no constant here, no app/ edit, no setting written. */
    public function test_this_inventory_writes_nothing(): void
    {
        $this->assertSame(
            ['paymera', 'nmi', 'khalti'],
            PaymentVerificationService::ENFORCED_PAYMENT_TYPES,
            'Something in this test run changed ENFORCED_PAYMENT_TYPES. It is a constant, so this '
            . 'can only mean the source changed -- and enrolling a gateway is a business change '
            . '(merchant wallets stop being credited), never a test fixture.'
        );

        $this->assertSame(['payhere', 'yookassa'], RetiredGateways::PROVIDERS);
    }
}
