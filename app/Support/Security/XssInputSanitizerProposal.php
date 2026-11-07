<?php

namespace App\Support\Security;

/**
 * A PROPOSAL, NOT A CONTROL. NOTHING CALLS THIS CLASS.
 * =================================================================================================
 *
 * Owner instruction of 2026-11-07, item 3: *"Keep the `XSS.php` input-corruption finding open.
 * Prepare a narrowly scoped, tested Phase 01-compatible repair proposal without weakening XSS
 * defenses."*
 *
 * This is that proposal, written as real code so it can be TESTED rather than described, and
 * deliberately **not wired into `app/Http/Middleware/XSS.php`**. `grep -rn
 * 'XssInputSanitizerProposal' app/` returns this file alone. Applying it is the owner's decision.
 *
 * ── THE DEFECT IT ADDRESSES ───────────────────────────────────────────────────────────────────────
 *
 * `XSS.php:53-59` runs over every string input on the whole `web` group, before validation and
 * before the insert:
 *
 *     $value = htmlspecialchars_decode($value);
 *     $value = str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);
 *
 * A bare SUBSTRING strip. Measured (`XssInputCorruptsIdentifiersTest`):
 *
 *     alerts@company.com      ->  s@company.com          stored wrong, silently
 *     Alberto-alert (a name)  ->  Alberto-               stored wrong, silently
 *     javascript@x.example    ->  REFUSED as "not a valid email address"
 *
 * That last one is the sharpest: stripping the whole local part leaves `@x.example`, which then
 * fails the handler's own `email` rule, so the merchant is told a valid address is invalid with
 * nothing connecting the refusal to a middleware that edited the value first.
 *
 * ── WHY THIS IS NOT A WEAKENING, WHICH IS THE OWNER'S CONDITION ──────────────────────────────────
 *
 * The two words are replaced by the two SHAPES that actually execute:
 *
 *     'javascript'  ->  the URL scheme `javascript:` (with its colon)
 *     'alert'       ->  a call `alert(`
 *
 * On every input where the old rule removed something dangerous, this one still does — asserted by
 * a differential test over a corpus, not by argument. On benign inputs containing either word as
 * ordinary text, this one leaves the value intact.
 *
 * AND IT TIGHTENS TWO THINGS THE OLD RULE GOT WRONG:
 *
 *  1. `htmlspecialchars_decode()` IS DROPPED. `CLAUDE.md` records why it mattered: the decode turns
 *     an encoded `&lt;` into a raw `<` BEFORE the strip looks for the encoded form, so
 *     `<img src=x onerror=…>` passed through whole as long as it avoided two words. Decoding
 *     attacker input before inspecting it is the bug, not the inspection.
 *  2. The raw `<` and `>` ARE handled, by encoding rather than stripping. The old rule targeted
 *     only the ENCODED forms, which the decode had just removed — so it never saw a raw angle
 *     bracket at all. Encoding is also non-destructive: `Arrived in <2 days` keeps its meaning,
 *     where `strip_tags()` would have eaten the rest of the sentence (a defect this codebase
 *     already measured and fixed once, in `ModuleStorefrontInputGuard`'s review path).
 *
 * ── WHAT IT DOES NOT CLAIM ───────────────────────────────────────────────────────────────────────
 *
 * **This is not an escaping layer and must not be presented as one.** `CLAUDE.md` is explicit that
 * the existing denylist is not one either. Output escaping at the sink is the real control; this is
 * defence in depth over inputs the application then stores. The value of the change is that it
 * stops corrupting identifiers while being no weaker at the door.
 *
 * ── SCOPE ────────────────────────────────────────────────────────────────────────────────────────
 *
 * Narrow by construction: one pure static method over one string. No new dependency, no behaviour
 * outside the transformation, nothing stateful, and `XSS.php`'s updater-gate and `$request->merge()`
 * halves are untouched by the proposal.
 */
final class XssInputSanitizerProposal
{
    /**
     * The two shapes the old denylist was reaching for, matched as shapes.
     *
     * `javascript\s*:` because `javascript&#9;:` and `javascript :` are the same scheme to a
     * browser's URL parser; `alert\s*\(` for the same reason on a call.
     */
    private const DANGEROUS_SHAPES = [
        '/javascript\s*:/i' => '',
        '/\balert\s*\(/i' => '',
    ];

    /**
     * Sanitise one input string.
     *
     * Order matters and is deliberate: the shapes are removed FIRST, on the raw value, and the
     * angle brackets encoded AFTERWARDS — so an encoded bracket can never be turned back into a
     * raw one before inspection, which is the inversion that made the old rule ineffective.
     */
    public static function clean(string $value): string
    {
        foreach (self::DANGEROUS_SHAPES as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        // ENCODED, NOT STRIPPED. Nothing is lost: '<2 days' stays readable, and the sinks that
        // consume these values decode exactly once (the same reasoning the review path in
        // ModuleStorefrontInputGuard records for `description`).
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }

    /**
     * The CURRENT middleware behaviour, reproduced verbatim so the two can be compared by
     * execution. Present only so the differential test has something to compare against; it is
     * not called by anything else.
     */
    public static function currentBehaviour(string $value): string
    {
        $value = htmlspecialchars_decode($value);

        return str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);
    }
}
