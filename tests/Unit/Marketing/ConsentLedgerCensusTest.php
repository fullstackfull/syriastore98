<?php

namespace Tests\Unit\Marketing;

use PHPUnit\Framework\TestCase;

/**
 * WHO MAY WRITE CONSENT, AND WHO MAY NOT. D8 / MKT-008.
 *
 * Three structural facts that no behavioural test can establish, because each is about code that does
 * NOT exist yet:
 *
 *   1. NOTHING GRANTS CONSENT FROM AN ORDER, A REGISTRATION OR AN IMPORT. The owner's direction is that
 *      consent is never inferred from historical orders or existing contacts. `GRANT_CALLERS` is the
 *      exhaustive list of files allowed to call a granting method, and it is EMPTY in this change: no
 *      opt-in surface has been built. The day one is, it is added here deliberately and a reviewer sees
 *      which file now records permission and from what form.
 *   2. ONLY `ConsentLedger` WRITES THE PROJECTION. A second writer could put a row in `granted` that no
 *      event supports, and `ConsentLedger::rebuildState()` would then disagree with it.
 *   3. NOTHING WRITES THE EVIDENCE THROUGH RAW SQL. Raw `DB::table('marketing_consent_events')` goes around
 *      the append-only model and builder.
 *
 * Comments are stripped before searching, so prose that names these things cannot satisfy or trip a check.
 */
class ConsentLedgerCensusTest extends TestCase
{
    /** @var list<string> repo-relative files allowed to call grant()/liftSuppression() on a ConsentLedger. EMPTY on purpose. */
    private const GRANT_CALLERS = [];

    /** @return array<string, string> repo-relative path => comment-stripped source */
    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        $out = [];

        foreach (['app', 'packages/workdo', 'routes', 'bootstrap'] as $dir) {
            $path = $root.'/'.$dir;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
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

                $out[$relative] = $code;
            }
        }

        return $out;
    }

    public function test_the_premise_the_ledger_and_its_models_exist(): void
    {
        $sources = $this->sources();

        foreach (['app/Services/Marketing/ConsentLedger.php', 'app/Models/MarketingContactState.php', 'app/Models/MarketingConsentEvent.php'] as $file) {
            $this->assertArrayHasKey($file, $sources, $file.' has moved: this census is reading the wrong tree');
        }
    }

    public function test_only_the_listed_files_call_a_granting_method(): void
    {
        $offenders = [];

        foreach ($this->sources() as $file => $code) {
            if ($file === 'app/Services/Marketing/ConsentLedger.php') {
                continue;
            }

            if (! str_contains($code, 'ConsentLedger')) {
                continue;
            }

            if (preg_match('/->\s*(grant|liftSuppression)\s*\(/', $code) && ! in_array($file, self::GRANT_CALLERS, true)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders,
            "These files call a granting method on the consent ledger and are not on the allow-list. Consent is never inferred "
            ."from an order, a registration or an import; a new opt-in surface must be added to GRANT_CALLERS on purpose:\n  "
            .implode("\n  ", $offenders));
    }

    public function test_only_the_ledger_writes_the_projection(): void
    {
        $offenders = [];

        foreach ($this->sources() as $file => $code) {
            if ($file === 'app/Services/Marketing/ConsentLedger.php' || $file === 'app/Models/MarketingContactState.php') {
                continue;
            }

            if (preg_match('/MarketingContactState\s*::\s*(create|insert|upsert|updateOrCreate|firstOrCreate|query\s*\(\s*\)\s*->\s*(update|delete|insert))/', $code)
                || preg_match('/new\s+MarketingContactState\b/', $code)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'a second writer of marketing_contact_states: '.implode(', ', $offenders));
    }

    public function test_nothing_writes_the_consent_tables_through_raw_sql(): void
    {
        $offenders = [];

        foreach ($this->sources() as $file => $code) {
            if (preg_match('/DB\s*::\s*table\s*\(\s*[\'"]marketing_(consent_events|contact_states|unsubscribe_tokens)[\'"]\s*\)/', $code)
                || preg_match('/(insert|update|delete)\s+(into\s+)?[`"\']?marketing_(consent_events|contact_states|unsubscribe_tokens)/i', $code)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'raw SQL against a consent table bypasses the append-only guard: '.implode(', ', $offenders));
    }

    public function test_the_gate_is_not_wired_into_any_sender_yet_and_that_is_deliberate(): void
    {
        // Enforcement is per channel and each seam needs its own classification of the senders behind it.
        // Until a seam is wired on purpose, an unwired gate must read as unwired, not as protection.
        $callers = [];

        foreach ($this->sources() as $file => $code) {
            if (str_starts_with($file, 'app/Services/Marketing/')) {
                continue;
            }

            if (str_contains($code, 'MarketingSendGate')) {
                $callers[] = $file;
            }
        }

        $this->assertSame([], $callers,
            'MarketingSendGate gained a caller. If that is the first wired seam, update this census AND the sender-classification '
            .'census together, so "wired" is a statement about named files and not an assumption.');
    }
}
