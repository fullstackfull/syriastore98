<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TOKEN SECURITY, STAGE 1 — OBSERVABILITY BEFORE ANY EXPIRY.
 * =============================================================================================
 *
 * The owner's decision (Decision Register row 13) approves token work *staged*: "observability →
 * explicit abilities → 30-day expiry for NEW merchant tokens only". This is the first stage, and
 * `docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md` §Stage 1 states why it comes first:
 *
 *     "Nothing today can answer 'how old is the oldest token still in use?'. ... DECISION GATE: if
 *      the oldest actively-used token is older than any expiry being considered, that expiry will
 *      log people out. Measure before choosing."
 *
 * So this command is an INSTRUMENT, not a measurement. The number that decides stage 3 lives in
 * production data, and nothing in this container has any. What is testable — and what these cases
 * test — is that the instrument reads the right column, buckets on the right boundaries, and does
 * not confuse "oldest token" with "oldest token still in use", which is the one distinction the
 * decision gate actually turns on.
 *
 * THE GATE IS REPORTED PER PRINCIPAL, which is more than the proposal asked for and is what the
 * approved stage 3 needs: the expiry is for **merchant tokens only**, so a single global "oldest
 * in use" figure would be decided by whichever customer has the oldest token and would answer the
 * wrong question.
 *
 * IT MUST LEAK NOTHING. `personal_access_tokens.token` is a SHA-256 hash of a live credential and
 * `tokenable_id` identifies a real person; a report that prints either turns an operational tool
 * into a disclosure. S4 in the security queue is exactly a token reaching a log. Asserted, not
 * assumed.
 */
class TokenInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = 'App\\Models\\Customer';
    private const MERCHANT = 'App\\Models\\User';
    private const DRIVER = 'App\\Models\\DeliveryBoy';

    public function test_it_counts_tokens_by_principal(): void
    {
        $this->token(self::CUSTOMER, 1, createdDaysAgo: 3);
        $this->token(self::CUSTOMER, 2, createdDaysAgo: 3);
        $this->token(self::MERCHANT, 1, createdDaysAgo: 3);
        $this->token(self::DRIVER, 1, createdDaysAgo: 3);

        $out = $this->report();

        $this->assertMatchesRegularExpression('/App.Models.Customer\s+2/', $out, $out);
        $this->assertMatchesRegularExpression('/App.Models.User\s+1/', $out, $out);
        $this->assertMatchesRegularExpression('/App.Models.DeliveryBoy\s+1/', $out, $out);
        $this->assertMatchesRegularExpression('/total tokens\s+4/', $out, $out);
    }

    /**
     * THE BOUNDARIES, EXACTLY. A bucket report whose edges are off by a day is worse than no
     * report: it reads as a measurement. 7/8, 30/31, 90/91 and 365/366 are each driven.
     */
    public function test_the_age_buckets_follow_created_at(): void
    {
        foreach ([1, 7, 8, 30, 31, 90, 91, 365, 366, 800] as $days) {
            $this->token(self::CUSTOMER, $days, createdDaysAgo: $days);
        }

        $out = $this->report();

        $this->assertMatchesRegularExpression('/0-7 days\s+2/', $out, $out);      // 1, 7
        $this->assertMatchesRegularExpression('/8-30 days\s+2/', $out, $out);     // 8, 30
        $this->assertMatchesRegularExpression('/31-90 days\s+2/', $out, $out);    // 31, 90
        $this->assertMatchesRegularExpression('/91-365 days\s+2/', $out, $out);   // 91, 365
        $this->assertMatchesRegularExpression('/over 365 days\s+2/', $out, $out); // 366, 800
    }

    public function test_never_used_is_its_own_row_and_not_a_recency_bucket(): void
    {
        $this->token(self::CUSTOMER, 1, createdDaysAgo: 400, lastUsedDaysAgo: null);
        $this->token(self::CUSTOMER, 2, createdDaysAgo: 400, lastUsedDaysAgo: 2);

        $out = $this->report();

        $this->assertMatchesRegularExpression('/never used\s+1/', $out, $out);
        $this->assertMatchesRegularExpression('/0-7 days ago\s+1/', $out, $out);
    }

    /**
     * THE DECISION GATE, AND THE DISTINCTION IT TURNS ON.
     *
     * A 400-day-old token that has NEVER been used tells you nothing about who an expiry would log
     * out — nobody is holding it. The figure that decides stage 3 is the oldest token someone is
     * still presenting. Conflating the two is the one way this report could be confidently wrong.
     */
    public function test_the_gate_ignores_a_token_nobody_is_using(): void
    {
        $this->token(self::MERCHANT, 1, createdDaysAgo: 400, lastUsedDaysAgo: null);
        $this->token(self::MERCHANT, 2, createdDaysAgo: 40, lastUsedDaysAgo: 1);

        $out = $this->report();

        $this->assertMatchesRegularExpression(
            '/oldest in use.*App.Models.User\s+40 days/s',
            $out,
            'the gate named the 400-day token nobody presents, so an expiry would be chosen against'
            . " a figure that describes nobody.\n" . $out
        );
    }

    public function test_the_gate_is_reported_per_principal(): void
    {
        $this->token(self::CUSTOMER, 1, createdDaysAgo: 900, lastUsedDaysAgo: 1);
        $this->token(self::MERCHANT, 1, createdDaysAgo: 20, lastUsedDaysAgo: 1);

        $out = $this->report();

        $this->assertMatchesRegularExpression('/App.Models.Customer\s+900 days/', $out, $out);
        $this->assertMatchesRegularExpression('/App.Models.User\s+20 days/', $out, $out);
    }

    public function test_it_reports_how_many_tokens_already_carry_an_expiry(): void
    {
        $this->token(self::MERCHANT, 1, createdDaysAgo: 1, expiresInDays: 30);
        $this->token(self::MERCHANT, 2, createdDaysAgo: 1);
        $this->token(self::CUSTOMER, 1, createdDaysAgo: 1);

        $out = $this->report();

        $this->assertMatchesRegularExpression('/with an expiry set\s+1/', $out, $out);
        $this->assertMatchesRegularExpression('/with no expiry\s+2/', $out, $out);
    }

    /**
     * WHAT THIS COVERS, EXACTLY, BECAUSE ITS FIRST VERSION OVERCLAIMED.
     *
     * It inspects `Artisan::output()`, i.e. what the command wrote through the CONSOLE. A bite that
     * leaked the hash with `echo` did NOT fail it, because `echo` writes past the buffer
     * `Artisan::output()` captures — so "the report prints no token value" was true only of one
     * channel. The companion case below closes that by asserting the command has no other channel
     * to leak through, and the two together are what the claim needs.
     */
    public function test_it_prints_no_token_value_and_no_principal_id(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => self::MERCHANT,
            'tokenable_id' => 987654321,
            'name' => 'auth_token',
            'token' => 'c0ffee' . str_repeat('a', 58),
            'abilities' => '["*"]',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
            'last_used_at' => now()->subDay(),
        ]);

        $out = $this->report();

        $this->assertStringNotContainsString('c0ffee', $out, 'the report printed a token hash');
        $this->assertStringNotContainsString('987654321', $out, 'the report printed a principal id');
    }

    public function test_it_says_so_when_there_are_no_tokens(): void
    {
        $this->assertSame(0, (int) DB::table('personal_access_tokens')->count(), 'the premise');

        $out = $this->report();

        $this->assertMatchesRegularExpression('/total tokens\s+0/', $out, $out);

        /*
         * The empty sections have to SAY they are empty. A gate that prints a heading and nothing
         * under it reads as "no principal is at risk" exactly as it reads as "the query returned
         * nothing", and those are different answers. Without this the case passes with both
         * emptiness guards deleted, which makes it a crash test wearing a report test's name.
         */
        $this->assertStringContainsString('(no tokens)', $out, $out);
        $this->assertStringContainsString('(no token is in use)', $out, $out);
    }

    /**
     * AN UNPARSEABLE TIMESTAMP IS EXCLUDED, NOT BUCKETED AS FRESH.
     *
     * `daysBetween()` returns null for a value it cannot parse, and the reason is in its docblock:
     * a malformed timestamp landing in "0-7 days" reads as a fresh token, which is the direction
     * that makes an expiry look safe when it is not. The claim is in a comment, so it gets a case.
     *
     * IT IS A SQLITE-ONLY STATE, and that matters for how to read it. MySQL cannot store
     * `'not-a-timestamp'` in a `timestamp` column — non-strict mode coerces it to a zero date,
     * which `Carbon::parse` accepts and which lands in "over 365 days". So this drives the code
     * path, not a production scenario, and nobody should read it as "production holds unparseable
     * timestamps".
     */
    public function test_a_timestamp_it_cannot_read_is_left_out_rather_than_called_fresh(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => self::MERCHANT,
            'tokenable_id' => 1,
            'name' => 'auth_token',
            'token' => hash('sha256', 'broken'),
            'abilities' => '["*"]',
            'created_at' => 'not-a-timestamp',
            'updated_at' => 'not-a-timestamp',
            'last_used_at' => null,
        ]);

        $out = $this->report();

        $this->assertMatchesRegularExpression('/total tokens\s+1/', $out, $out);
        $this->assertMatchesRegularExpression(
            '/0-7 days\s+0/',
            $out,
            'a token whose created_at cannot be parsed was counted as less than a week old, so a'
            . " 30-day expiry would look safe for it.\n" . $out
        );
    }

    /**
     * THE COMMAND OPENS NO CHANNEL BUT THE CONSOLE — A RATCHET, NOT A CLOSURE PROOF.
     *
     * S4 in the security queue is a token reaching a LOG. A report that aggregates correctly and
     * then writes a row through a log, a file, an email or an HTTP call would satisfy the output
     * case above and still disclose, so this scans for every such spelling the codebase could
     * reach. Comments are stripped first, because this file DISCUSSES logging and disclosure and
     * the discussion must not be what the assertion reads.
     *
     * WHAT IT IS NOT. An earlier version of this docblock said "the console is the only channel",
     * and a review established that is false independently of the command's source: if the command
     * throws, Laravel's exception handler writes the exception to `laravel.log`. **Nothing
     * sensitive can reach it**, for two reasons worth keeping written down — the `token` column is
     * never selected (so no row dump could carry the hash), and this query's only bindings are the
     * paging integers (so a `QueryException` message has no credential to interpolate). It also
     * scans ONE file, so a future collaborator class would be invisible to it, and nothing here
     * asserts the command performs no WRITE — "read-only" is still a docblock claim.
     */
    public function test_the_command_writes_through_no_channel_but_the_console(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/TokenInventoryReport.php')
        );

        /* Comments are stripped first: this file DISCUSSES logging and disclosure, and the
           discussion must not be what the assertion reads. */
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        /*
         * MATCHED AS WHOLE CALLS, NOT SUBSTRINGS, and the first version of this list got it wrong
         * in both directions: `printf` matched the `sprintf()` this command legitimately uses for
         * its own column alignment, and a bare `info(` would have matched `$this->info()`, which
         * IS the console. The lookbehind excludes an identifier character, a `$` and a `>`, so a
         * method call on an object is not a free-function call.
         */
        $functions = [
            // logging
            'logger', 'info', 'report', 'syslog', 'error_log', 'trigger_error',
            // direct output that bypasses the console buffer
            'echo', 'print', 'printf', 'vprintf', 'var_dump', 'var_export', 'print_r',
            // debug helpers
            'dd', 'dump',
            // files -- a report can be written out as easily as printed
            'file_put_contents', 'fwrite', 'fputs', 'fputcsv',
        ];

        foreach ($functions as $fn) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![A-Za-z0-9_$>])' . preg_quote($fn, '/') . '\s*[( ]/',
                $code,
                'the inventory command writes through `' . $fn . '()`, which the leak case above'
                . ' cannot observe: it reads Artisan::output(). A token hash or a principal id sent'
                . ' that way is exactly S4.'
            );
        }

        /* Facades and containers, where a substring is already unambiguous. */
        foreach (['Log::', 'Mail::', 'Notification::', 'Http::', "app('log')", 'app("log")'] as $facade) {
            $this->assertStringNotContainsString(
                $facade,
                $code,
                'the inventory command reaches `' . $facade . '`, a channel the leak case above'
                . ' cannot observe.'
            );
        }
    }

    /**
     * A ROW DELETED WHILE THE REPORT IS READING MUST NOT MAKE IT SKIP A DIFFERENT ROW.
     *
     * THE DEFECT THIS CLOSES, found by review, was in the first version of this command: it paged
     * with `chunk()`, which is `LIMIT n OFFSET m`. An `UPDATE` cannot disturb that — and
     * `CustomAuth:88` updating `last_used_at` on every request was the interference I had thought
     * about — but a DELETE below the current offset shifts the window up and skips exactly one row
     * per delete. And this table is deleted from constantly: **every login revokes first**, via
     * `$user->tokens()->delete()` at ten call sites (merchant login, driver login, customer login,
     * three logouts, three password resets). On a production run, deletes are not a corner case.
     *
     * THE ERROR DIRECTION IS THE BAD ONE. A skipped row can be the single oldest in-use token,
     * which is the one number stage 3's expiry is chosen against — so the under-count makes a
     * SHORTER expiry look safer than it is. `chunkById()` is keyset paging (`where id > last`) and
     * is immune.
     *
     * DRIVEN, NOT ASSERTED FROM THE SOURCE: the page size is shrunk to 2 on the registered command
     * instance, five tokens are inserted, and a `DB::listen` hook deletes the lowest id after the
     * first page — which is the interleave a login produces. The token that offset paging would
     * skip is the 900-day one, so the gate is the thing that changes.
     */
    public function test_a_row_deleted_mid_read_does_not_make_it_skip_another(): void
    {
        /* ids 1..5; the third is the only old one, and it is the row offset paging loses. */
        foreach ([10, 10, 900, 10, 10] as $age) {
            $this->token(self::MERCHANT, 1, createdDaysAgo: $age, lastUsedDaysAgo: 1);
        }

        $ids = DB::table('personal_access_tokens')->orderBy('id')->pluck('id')->all();
        $this->assertCount(5, $ids, 'the premise: five rows');

        $command = \Illuminate\Support\Facades\Artisan::all()['tokens:inventory'];
        $pageSize = new \ReflectionProperty($command, 'pageSize');
        $pageSize->setAccessible(true);
        $pageSize->setValue($command, 2);

        $deleted = false;
        DB::listen(function ($query) use (&$deleted, $ids) {
            if ($deleted || ! str_contains($query->sql, 'personal_access_tokens')) {
                return;
            }
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            /* What a login does, in the middle of the read. */
            $deleted = true;
            DB::table('personal_access_tokens')->where('id', $ids[0])->delete();
        });

        $out = $this->report();

        $this->assertTrue($deleted, 'the premise: a row really was deleted during the read');

        $this->assertMatchesRegularExpression(
            '/oldest in use.*App.Models.User\s+900 days/s',
            $out,
            'the report skipped the 900-day token because a row below the page offset was deleted'
            . ' mid-read -- so the gate under-reports and a shorter expiry looks safer than it is.'
            . " Offset paging reads this as 10 days.\n" . $out
        );
    }

    /**
     * THE GATE CARRIES ITS OWN DORMANCY, because it is an upper bound set by ONE token.
     *
     * A review was right that "has ever been presented" is not "is still in use": a token last
     * seen 380 days ago is as unheld as one never used, and an orphan — tokenable row deleted, so
     * `CustomAuth` refuses it — can never be presented again yet still pins the maximum. Both
     * inflate the figure in the conservative direction, so neither is dangerous; what is dangerous
     * is a reader unable to tell an inflated bound from a live one.
     */
    public function test_the_gate_shows_how_dormant_its_oldest_token_is(): void
    {
        $this->token(self::MERCHANT, 1, createdDaysAgo: 900, lastUsedDaysAgo: 380);
        $this->token(self::MERCHANT, 2, createdDaysAgo: 20, lastUsedDaysAgo: 1);

        $out = $this->report();

        $this->assertMatchesRegularExpression(
            '/App.Models.User\s+900 days\s+\(last seen 380 days ago\)/',
            $out,
            'the gate reports 900 days without saying the token behind it has not been seen for a'
            . " year, so a dormant outlier is indistinguishable from a live constraint.\n" . $out
        );
    }

    /**
     * AND THE COUNT THE ROLLOUT ACTUALLY TURNS ON.
     *
     * The gate is a maximum with no cardinality, so it cannot answer "how many merchants would the
     * approved expiry interrupt" — and the age distribution is over ALL tokens, never-used ones
     * included, so no percentile can be read off that either. The approved figure is 30 days
     * (Decision Register row 13), so the report states how many in-use tokens that would have
     * refused, per principal.
     */
    public function test_it_counts_how_many_in_use_tokens_the_approved_expiry_would_refuse(): void
    {
        $this->token(self::MERCHANT, 1, createdDaysAgo: 10, lastUsedDaysAgo: 1);
        $this->token(self::MERCHANT, 2, createdDaysAgo: 40, lastUsedDaysAgo: 1);
        $this->token(self::MERCHANT, 3, createdDaysAgo: 900, lastUsedDaysAgo: 1);
        /* Never used, and 900 days old: it must count in neither figure. */
        $this->token(self::MERCHANT, 4, createdDaysAgo: 900, lastUsedDaysAgo: null);

        $out = $this->report();

        $this->assertMatchesRegularExpression(
            '/older than 30 days.*App.Models.User\s+2 of 3 in use/s',
            $out,
            'the count of tokens the approved 30-day expiry would have refused is wrong. It must'
            . ' exclude the 10-day token (inside the expiry) and the never-used one (nobody holds'
            . " it), leaving 2 of the 3 in use.\n" . $out
        );
    }

    // ---------------------------------------------------------------- fixture

    /**
     * `Artisan::call()` rather than `$this->artisan()`: the pending-command helper captures its
     * output in its own buffer and leaves `Artisan::output()` empty, which the first version of
     * this file asserted against and every case failed on an empty string.
     */
    private function report(): string
    {
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('tokens:inventory'));

        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertNotSame('', trim($out), 'the command produced no output at all');

        return $out;
    }

    private function token(
        string $type,
        int $id,
        int $createdDaysAgo,
        ?int $lastUsedDaysAgo = null,
        ?int $expiresInDays = null,
    ): void {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => $type,
            'tokenable_id' => $id,
            'name' => 'auth_token',
            'token' => hash('sha256', $type . $id . $createdDaysAgo . uniqid()),
            'abilities' => '["*"]',
            'created_at' => now()->subDays($createdDaysAgo),
            'updated_at' => now()->subDays($createdDaysAgo),
            'last_used_at' => $lastUsedDaysAgo === null ? null : now()->subDays($lastUsedDaysAgo),
            'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
        ]);
    }
}
