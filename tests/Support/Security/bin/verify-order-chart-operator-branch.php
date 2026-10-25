#!/usr/bin/env php
<?php
/**
 * P2-02-12: HomeController::getOrderChart() must reach PlanOrder ONLY for the
 * platform operator.
 *
 * WHY THIS EXISTS, AND WHY IT IS A SCRIPT RATHER THAN A TEST
 * ---------------------------------------------------------
 * The independent review of this slice established that **reverting the fix
 * would not fail a single thing in the suite**:
 *
 *   - no test in tests/ references getOrderChart at all;
 *   - OperatorCheckCensusTest's construct assertion covers three NAMED files
 *     (OrderPolicy, OrderDocumentAccessGuard, OrderController) and
 *     HomeController is not one of them;
 *   - and its regexes key on `'super admin'` / PLATFORM_ADMIN comparisons,
 *     while the line this fix REMOVED compared against `'admin'` -- so even if
 *     HomeController were added to that list, these patterns would not have
 *     caught the defect.
 *
 * A PHPUnit test would be the natural home, but Composer cannot install
 * (git.yoomoney.ru is blocked -- see PHASE-02-TEST-EVIDENCE.md §0), so nothing
 * in tests/Unit or tests/Feature can be EXECUTED here. An unrun test is a claim,
 * not evidence. CLAUDE.md §3 says evidence that cannot live in the suite goes in
 * tests/Support/Security/bin/ as an executable script stating its own
 * preconditions -- and a conditionally-skipped test would break the Feature
 * gate's declared skip set. So: a static guard that runs TODAY, with no vendor/.
 *
 * PRECONDITIONS: php >= 8.0. No vendor/, no database, no network.
 *
 * WHAT IT CHECKS -- the structure, not a mention. "Does the file say
 * isOperator" is the assertion that already let a drift hide once
 * (OperatorCheckCensusTest.php:120-128): OrderController used the helper in one
 * method while another carried its own hand-rolled comparison. So this asserts
 * the BRANCH: that PlanOrder sits in the operator arm and Order in the other.
 *
 * WHAT IT DOES NOT CHECK: whether the chart is correct, whether PlanOrder is
 * tenant-scoped (it is not -- it is deliberately platform-wide for the Super
 * Admin dashboard), or anything at runtime. It is a source-structure guard.
 */

/* dirname(__DIR__, 4): this file is at tests/Support/Security/bin/, so bin ->
 * Security -> Support -> tests -> repo root is FOUR levels. The first version
 * said 3, resolved to tests/app/Http/..., and exited 2 -- the same off-by-one
 * that left docs/design-system/tools/fixtures/sidebar-nav.php rendering an
 * empty nav. It is caught here only because an unreadable file is a hard exit
 * rather than a silent zero-assertion pass. */
$root = dirname(__DIR__, 4);
$file = $root . '/app/Http/Controllers/HomeController.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = '', bool $always = false): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    // The detail is the FAILURE message for the boolean checks, so printing it
    // next to PASS read as "a comparison is back" on a clean run. Details that
    // are measurements rather than complaints are passed as $always.
    printf("%-6s %s%s\n", $ok ? 'PASS' : 'FAIL', $name,
        (!$ok || $always) && $detail !== '' ? "  -- {$detail}" : '');
}

$source = @file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(2);
}

/** Strip comments so prose cannot satisfy any assertion below. */
function code_only(string $php): string
{
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                // keep newlines so line structure survives
                $out .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$code = code_only($source);

/** The body of one method, brace-matched. */
function method_body(string $code, string $name): ?string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)[^{]*\{/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $i = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $start = $i;
    $len = strlen($code);
    while ($i < $len && $depth > 0) {
        if ($code[$i] === '{') $depth++;
        elseif ($code[$i] === '}') $depth--;
        $i++;
    }
    return $depth === 0 ? substr($code, $start, $i - $start - 1) : null;
}

$body = method_body($code, 'getOrderChart');
check('getOrderChart() is present and brace-balanced', $body !== null);
if ($body === null) {
    echo "\n{$pass} PASSED, {$fail} FAILED\n";
    exit(1);
}

// ---- 1. the discriminator goes through the shared helper ----------------
check(
    'the branch is decided by UserPolicy::isOperator()',
    (bool) preg_match('/UserPolicy::isOperator\s*\(/', $body)
);

// ---- 2. no hand-rolled operator comparison beside it --------------------
// This is the construct the census learned to key on: a second spelling of
// "is this the operator" in the same method is how two gates drift apart.
check(
    'it does not compare users.type against a literal',
    !preg_match('/->type\s*(?:===|==|!==|!=)\s*[\'"]/', $body),
    'a `$user->type == \'...\'` comparison is back'
);
check(
    'it does not re-normalise the operator type by hand',
    !preg_match('/(?:strtolower|strtoupper|trim|rtrim|ltrim)\s*\([^;]{0,160}?[\'"]super admin[\'"]/i', $body)
);

// ---- 3. THE STRUCTURAL CLAIM: PlanOrder is in the operator arm ----------
// Locate the if/else on $isOperator and assert which table is in which arm. A
// mention check would pass even if the arms were swapped.
//
// BOTH SPELLINGS ARE ACCEPTED, and that matters. A first version keyed only on
// `if (! $isOperator)`, which meant the semantically identical
// `if ($isOperator) { PlanOrder } else { Order }` would have FAILED a guard on
// correct code -- and its message, "no `if (! $isOperator)` with an else was
// found", would have sent the reader hunting for a missing else instead of
// pointing at the real shape. A check that is wrong in the strict direction
// still costs someone an afternoon. So the negation is captured and the arms
// are mapped to operator/non-operator accordingly.
$armsOk = false;
$detail = 'no `if (...$isOperator)` with an else was found';
if (preg_match('/if\s*\(\s*(!\s*)?\$isOperator\s*\)\s*\{/', $body, $m, PREG_OFFSET_CAPTURE)) {
    $negated = trim($m[1][0] ?? '') === '!';
    $i = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $start = $i;
    while ($i < strlen($body) && $depth > 0) {
        if ($body[$i] === '{') $depth++;
        elseif ($body[$i] === '}') $depth--;
        $i++;
    }
    $thenArm = substr($body, $start, $i - $start - 1);
    $rest = substr($body, $i);
    $elseArm = '';
    if (preg_match('/^\s*else\s*\{/', $rest, $em)) {
        $j = strlen($em[0]);
        $depth = 1;
        $estart = $j;
        while ($j < strlen($rest) && $depth > 0) {
            if ($rest[$j] === '{') $depth++;
            elseif ($rest[$j] === '}') $depth--;
            $j++;
        }
        $elseArm = substr($rest, $estart, $j - $estart - 1);
    }

    // `if (! $isOperator)` puts the non-operator code in the THEN arm;
    // `if ($isOperator)` puts the operator code there. Map, then assert.
    $operatorArm    = $negated ? $elseArm : $thenArm;
    $nonOperatorArm = $negated ? $thenArm : $elseArm;

    $opHasPlan     = (bool) preg_match('/\bPlanOrder::/', $operatorArm);
    $nonOpHasPlan  = (bool) preg_match('/\bPlanOrder::/', $nonOperatorArm);
    $nonOpHasOrder = (bool) preg_match('/(?<![A-Za-z])Order::/', $nonOperatorArm);

    $armsOk = $opHasPlan && !$nonOpHasPlan && $nonOpHasOrder;
    $detail = sprintf(
        'spelling=%s | non-operator arm: Order=%s PlanOrder=%s | operator arm: PlanOrder=%s',
        $negated ? 'if (! $isOperator)' : 'if ($isOperator)',
        $nonOpHasOrder ? 'yes' : 'no',
        $nonOpHasPlan ? 'yes' : 'no',
        $opHasPlan ? 'yes' : 'no'
    );
}
check('PlanOrder is reached ONLY in the operator arm, Order in the other', $armsOk, $detail, true);

// ---- 4. PlanOrder appears nowhere else in the method --------------------
$planCount = preg_match_all('/\bPlanOrder::/', $body);
check(
    'PlanOrder is referenced exactly once in the method',
    $planCount === 1,
    "found {$planCount}",
    true
);

// ---- 5. ANTI-VACUITY -----------------------------------------------------
// Every assertion above is a regex over text, and a regex that stops matching
// fails silently and forever. These drive the detectors against the shapes
// they exist to catch, including the exact pre-fix line.
$preFix = 'if (auth()->user()->type == \'admin\') { $data = Order::select(1); } else { $data = PlanOrder::select(1); }';
check(
    'the literal-comparison detector catches the pre-fix line',
    (bool) preg_match('/->type\s*(?:===|==|!==|!=)\s*[\'"]/', $preFix)
);
$swapped = 'if (! $isOperator) { $data = PlanOrder::select(1); } else { $data = Order::select(1); }';
check(
    'the arm check would catch the arms being SWAPPED',
    (bool) preg_match('/if\s*\(\s*!\s*\$isOperator\s*\)\s*\{\s*\$data = PlanOrder::/', $swapped)
);
check(
    'comments cannot satisfy the helper check',
    !preg_match('/UserPolicy::isOperator\s*\(/', code_only("<?php // UserPolicy::isOperator()\n\$x=1;"))
);

echo "\n{$pass} PASSED, {$fail} FAILED\n";
exit($fail ? 1 : 0);
