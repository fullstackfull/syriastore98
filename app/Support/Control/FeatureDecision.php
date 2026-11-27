<?php

namespace App\Support\Control;

/**
 * PHASE 06 `CTRL-001` / `CTRL-002` — THE ANSWER IS THREE-VALUED, AND THAT IS THE WHOLE DESIGN.
 * =================================================================================================
 *
 * A boolean answer would force every caller to pick a meaning for "no flag row exists", and the two
 * available meanings are both wrong for this codebase:
 *
 *  - **false** would make a caller that gates existing behaviour refuse EVERYTHING until somebody
 *    remembered to insert a row. CLAUDE.md records what that failure looks like here: a merchant
 *    whose `add_on_managers` resolves nothing is redirected to the dashboard with HTTP 200, "which
 *    a status-code assertion photographs as the screen". A whole surface would go missing and the
 *    tests would stay green.
 *  - **true** would make a typo in a flag key silently enable a feature, which is the opposite
 *    failure and the more dangerous one.
 *
 * So the resolver says **`NotControlled`** and the caller decides. That is what makes this control
 * plane safe to retrofit onto 70 vendored modules one at a time: a surface with no flag row behaves
 * byte-identically to the way it behaved before the plane existed, and
 * `FeatureControlResolutionTest::test_an_undeclared_feature_is_not_controlled_rather_than_off`
 * asserts it.
 *
 * `FeatureControl::enabled()` exists for callers that genuinely have a default in mind, and it takes
 * that default as an argument rather than choosing one for them.
 */
enum FeatureDecision: string
{
    /** This subject has the feature. */
    case Allowed = 'allowed';

    /** This subject does not have the feature, and a flag says so. */
    case Denied = 'denied';

    /** No flag governs this key at all, so the control plane has no opinion. */
    case NotControlled = 'not_controlled';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }

    public function isDenied(): bool
    {
        return $this === self::Denied;
    }

    public function isControlled(): bool
    {
        return $this !== self::NotControlled;
    }
}
