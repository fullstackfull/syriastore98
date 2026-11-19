#!/usr/bin/env python3
"""
REVERT-AND-CONFIRM FOR THE "A ROOT OF 1 IS A TENANT" FIXES (P1-SEC-67b, P1-SEC-67c, P1-SEC-67d), FOR P1-SEC-65, FOR THE OWNER'S DETECTION QUERIES, FOR P1-SEC-72, P1-SEC-70, P1-SEC-68 AND P1-SEC-77
====================================================================================================================================================================================================

WHY THIS IS A SCRIPT AND NOT A TEST. The project's rule is stricter than "a failing test first": after the fix, revert it and confirm the
test fails again -- a test that passes for the wrong reason is the one that has actually bitten here. A reverted fix cannot live in the
suite (it would have to edit application code while the suite runs), so the evidence lives here, as the project's rule for evidence that
cannot be a test requires. Until 2026-11-18 these mutants lived in a session scratchpad and the pull request quoted their counts; an audit
of that description pointed out that a reader could not re-run them. Now they can.

WHAT IT DOES. For each mutant below it edits ONE OR TWO REAL SOURCE FILES (an exact string replacement, which must occur exactly once unless
an edit names a count as its fourth element, in which case every one of that many occurrences is replaced), runs the named
test files, and restores the files byte for byte, verified by sha256. A mutant is KILLED when the test run exits non-zero. Each group's
tests are first run UNMUTATED and must pass, so that "non-zero" can only mean "the mutant was noticed" (a baseline that is red would make
every mutant look killed).

THE `detect` GROUP MUTATES A DOCUMENT, NOT CODE: the SQL the owner is asked to run lives in docs/security/P01-SECURITY-WORK-QUEUE.md, and
StalePointerDetectionQueryTest executes it as written. A query that is wrong in the document is wrong where it matters, so the document is the
thing mutated (a query once offered there could not return the rows it was for, and nothing had run it).

NOT EVERY MUTANT IS A REVERT. Each group holds the plain revert to the old predicate (S1, R1, H1, L1, L4, W1, G1, U1) and VARIANTS of the
fix: the narrower question ("refuse only a root of 1"), a fail-open form, a missing operator-first limb, a drifted copy of the rule. The
variants are what tell a fix that is right from one that merely differs from the old code. Several mutants of one group exercise one
behaviour and several pin parity with UserPolicy rather than a live path; docs/security/P01-SECURITY-WORK-QUEUE.md (P1-SEC-64b, g) to j))
says which, so that a count is not read as more than it is.

DECLARED SURVIVORS. A mutant listed in DECLARED_SURVIVORS is expected to SURVIVE, with the reason written down. The outcome set is asserted
in BOTH directions, as the Feature suite's known-outcome gate does: an undeclared survivor fails the run, and so does a declared survivor
that is now killed (delete the declaration).

PRECONDITIONS, AND WHAT WILL GO WRONG IF THEY ARE IGNORED.
  * Run it from a checkout whose vendor/ is installed, with PHP on the PATH. It runs `php artisan test`, which uses SQLite in memory
    (phpunit.xml). It never touches a database server and never talks to production.
  * IT EDITS APPLICATION SOURCE WHILE IT RUNS. Do not run it while a test gate, a review or a deploy is reading the tree, and do not edit
    the mutated files meanwhile. It refuses to start if a file it would mutate has uncommitted changes (so the restore target is known).
  * If a restore does not verify it STOPS at once with exit status 9 and names the file: run `git checkout -- <file>` before anything else.
  * A mutant whose "old" string is no longer in the file exactly once is reported NOT APPLIED and fails the run (exit 2): the code has
    moved and the mutant must be re-expressed, not skipped.

USAGE
    python3 tests/Support/Security/bin/mutate-tenant-root-fixes.py                 # every group
    python3 tests/Support/Security/bin/mutate-tenant-root-fixes.py writer          # one group
    python3 tests/Support/Security/bin/mutate-tenant-root-fixes.py settings H1 H9  # some mutants of one group (label prefixes)
    python3 tests/Support/Security/bin/mutate-tenant-root-fixes.py --list          # print the mutants and exit

EXIT STATUS  0 the outcome set is exactly as declared · 1 an undeclared survivor, or a declared survivor that is now killed ·
             2 a mutant did not apply or did not parse · 3 a group's unmutated baseline is not green · 9 a restore did not verify
"""
import hashlib
import os
import re
import signal
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]

# label prefix => why this mutant is expected to survive
DECLARED_SURVIVORS = {
    'W5': "It asks the merchant question of the writer's CREATOR instead of the writer. For the six principal kinds the tests build "
          "(operator, merchant, a merchant's staff, a self-demoted merchant, their staff, staff the operator created) the two questions "
          "have the same answer. A user created BY a merchant's staff member separates them (UserController::store stamps created_by "
          "with the creator's own id); no test pins that case and its reachability is doubtful.",
    'OF12': "The SECOND layer allows every class: `unserialize($bytes, ['allowed_classes' => true])`. The grammar in front of it refuses every byte string that "
            "could build an object -- an object, an enum, a custom-serialised object, a reference -- before unserialize() is called, so over the bytes that reach it "
            "the option cannot change the answer: EQUIVALENT BY CONSTRUCTION. It is kept as the belt to the grammar's braces (a future grammar bug would otherwise be an "
            "object-injection door again), and the grammar's own fuzz case pins that PHP agrees with it on everything it accepts.",
    'RT17': "A NUL byte is kept in a text node: `str_replace(\"\\0\", '', $node->data)` becomes `$node->data`. EQUIVALENT ON THIS PARSER: libxml 2.9.14 (PHP 8.3.6) never hands a NUL to "
            "a text node or an attribute value -- it reads it as a space, or ends the parse there and drops the rest of the input (probe in the work queue, P1-SEC-75) -- so the line cannot "
            "change an output today. It is kept as the belt for a libxml that does; the Chromium differential and the corpus (which holds NUL bytes) would show it if one did.",
    'RT52': "The parser's own refusal returns the input RAW: `return htmlspecialchars($html, ...)` becomes `return $html`. UNREACHABLE ON THIS PARSER, so no test can reach it: libxml 2.9.14 never made "
            "loadHTML fail or throw on 43,000 inputs (random bytes, 400 KB attribute floods, 60,000-deep nests, NULs, invalid UTF-8, meta charsets -- two reviewers' fuzz and the author's), and the "
            "wrapper in front of the input is never empty. The branch is a belt for a libxml that refuses; it returns the input ESCAPED on purpose, and the docblock says it cannot be reached.",
    'SD27': "A delete that is ALLOWED and then FAILS must answer false, as Filesystem::delete() does: `$success = parent::delete($real) && $success;` becomes "
            "`parent::delete($real);`. UNTESTED, NOT EQUIVALENT: a failure after the verdict (a read-only directory, an immutable file) cannot be provoked by a test that "
            "runs as root, for whom neither fails, and the class is final, so its parent's call cannot be replaced. Recorded in the work queue (P1-SEC-70 d).",
}

GROUPS = [
    {
        "key": 'scope',
        "title": "P1-SEC-67b -- StoreScope::ownedStoreIds(), the scope's copy of the merchant-root rule, and Api\\DashboardController::resolveManageableUser()",
        "tests": ['tests/Feature/Security/TenantRootSinksTest.php'],
        "mutants": [
            ('S1 the scope no longer asks whether the root is a merchant (the old owned set)', [
                ('app/Scopes/StoreScope.php',
                 'if (! static::rootIsAMerchant($creatorId)) {',
                 'if (false) {'),
            ]),
            ('S2 rootIsAMerchant answers yes to everything', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 '        return true;\n'),
            ]),
            ('S3 rootIsAMerchant asks the narrower question (root is not user 1)', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 '        return $rootId > 1;\n'),
            ]),
            ('S4 rootIsAMerchant drops the case normalisation', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 "        return is_string($type) && rtrim($type, ' ') === 'admin';\n"),
            ]),
            ('S5 rootIsAMerchant drops the trailing-space normalisation', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 "        return is_string($type) && strtolower($type) === 'admin';\n"),
            ]),
            ('S6 rootIsAMerchant also trims a leading space (looser than the policy)', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 "        return is_string($type) && strtolower(trim($type)) === 'admin';\n"),
            ]),
            ('S7 rootIsAMerchant accepts any string that merely starts with admin', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 "        return is_string($type) && str_starts_with(strtolower($type), 'admin');\n"),
            ]),
            ('S8 a root that cannot be read counts as a merchant', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 "        return $type === null || (is_string($type) && strtolower(rtrim($type, ' ')) === 'admin');\n"),
            ]),
            ('S9 the gate is applied only to a root equal to user 1', [
                ('app/Scopes/StoreScope.php',
                 'if (! static::rootIsAMerchant($creatorId)) {',
                 'if ($creatorId === 1 && ! static::rootIsAMerchant($creatorId)) {'),
            ]),
            ("S10 the empty set is replaced by the root's stores anyway", [
                ('app/Scopes/StoreScope.php',
                 '                $ids = [];\n            } else {',
                 "                $ids = array_values(array_map(static fn ($id) => (int) $id, DB::table('stores')->where('created_by', $creatorId)->pluck('id')->all()));\n            } else {"),
            ]),
            ('S11 the gate asks only principals that define creatorId() (the first draft)', [
                ('app/Scopes/StoreScope.php',
                 'if (! static::rootIsAMerchant($creatorId)) {',
                 "if (is_object($user) && method_exists($user, 'creatorId') && ! static::rootIsAMerchant($creatorId)) {"),
            ]),
            ('S12 rootIsAMerchant uses the default rtrim character list (tab and newline accepted)', [
                ('app/Scopes/StoreScope.php',
                 "        return is_string($type) && strtolower(rtrim($type, ' ')) === 'admin';\n",
                 "        return is_string($type) && strtolower(rtrim($type)) === 'admin';\n"),
            ]),
            ('P1 the POLICY drifts: it trims a leading space too (the parity test must notice)', [
                ('app/Policies/UserPolicy.php',
                 "return strtolower(rtrim((string) $root->type, ' ')) === 'admin';",
                 "return strtolower(trim((string) $root->type)) === 'admin';"),
            ]),
            ('P2 the POLICY drifts: it stops folding case', [
                ('app/Policies/UserPolicy.php',
                 "return strtolower(rtrim((string) $root->type, ' ')) === 'admin';",
                 "return rtrim((string) $root->type, ' ') === 'admin';"),
            ]),
            ('R1 resolveManageableUser is the old predicate', [
                ('app/Http/Controllers/Api/DashboardController.php',
                 '        $tenant = \\App\\Policies\\UserPolicy::merchantTenantRoot($actor);\n\n        if ($tenant === null || empty($target->created_by)) {',
                 '        $tenant = $actor->creatorId();\n\n        if (empty($tenant) || empty($target->created_by)) {'),
            ]),
            ('R2 resolveManageableUser keeps the helper but treats null as the operator id', [
                ('app/Http/Controllers/Api/DashboardController.php',
                 '        if ($tenant === null || empty($target->created_by)) {',
                 '        $tenant = $tenant ?? 1;\n\n        if (empty($target->created_by)) {'),
            ]),
            ('R3 resolveManageableUser drops the identity limb (own row)', [
                ('app/Http/Controllers/Api/DashboardController.php',
                 "        if ((int) $target->id === (int) $actor->id) {\n            return $target;\n        }\n\n        if ($actor->type === 'super admin') {",
                 "        if ($actor->type === 'super admin') {"),
            ]),
        ],
    },
    {
        "key": 'settings',
        "title": 'P1-SEC-67c -- getAdminAllSetting() (app/Helper/helper.php)',
        "tests": ['tests/Feature/Security/OperatorRootedSettingsTest.php'],
        "mutants": [
            ('H1 the old predicate (User::find(created_by)) for every principal', [
                ('app/Helper/helper.php',
                 '$creator = User::find($user->created_by);\n\n                if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {\n                    return [];\n                }\n\n                $user = $creator;',
                 '$user = User::find($user->created_by);'),
            ]),
            ('H2 fail OPEN: a non-merchant creator becomes "no user" and falls through to the lowest-id merchant', [
                ('app/Helper/helper.php',
                 '$creator = User::find($user->created_by);\n\n                if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {\n                    return [];\n                }\n\n                $user = $creator;',
                 '$creator = User::find($user->created_by);\n\n                $user = \\App\\Policies\\UserPolicy::isMerchantRow($creator) ? $creator : null;'),
            ]),
            ('H3 no merchant test: any creator that exists', [
                ('app/Helper/helper.php',
                 'if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {',
                 'if ($creator === null) {'),
            ]),
            ('H4 only the operator is refused as a creator (the narrower question)', [
                ('app/Helper/helper.php',
                 'if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {',
                 'if ($creator === null || \\App\\Policies\\UserPolicy::isOperator($creator)) {'),
            ]),
            ('H5 only a root of 1 is refused (by id)', [
                ('app/Helper/helper.php',
                 'if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {',
                 'if ($creator === null || (int) $creator->id === 1) {'),
            ]),
            ('H6 the rule is applied to every principal, a Customer included (the first draft)', [
                ('app/Helper/helper.php',
                 'if ($user instanceof User) {',
                 'if (true) {'),
            ]),
            ('H7 a non-User principal is refused too (a customer gets no settings)', [
                ('app/Helper/helper.php',
                 '} else {\n                $user = User::find($user->created_by);\n            }',
                 '} else {\n                return [];\n            }'),
            ]),
            ('H8 half-fix: an explicit created_by === 1 refusal, the rest unguarded', [
                ('app/Helper/helper.php',
                 '$creator = User::find($user->created_by);\n\n                if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {\n                    return [];\n                }\n\n                $user = $creator;',
                 'if ((int) $user->created_by === 1) {\n                    return [];\n                }\n\n                $user = User::find($user->created_by);'),
            ]),
            ('H9 the creator is read twice by an UNCACHED second read (the performance regression the review named; a cached duplicate is invisible to a query count)', [
                ('app/Helper/helper.php',
                 '$creator = User::find($user->created_by);\n',
                 "$creator = User::find($user->created_by);\n                \\Illuminate\\Support\\Facades\\DB::table('users')->where('id', $user->created_by)->first();\n"),
            ]),
        ],
    },
    {
        "key": 'listings',
        "title": 'P1-SEC-67c -- User::switchableStores() and UserController::UserUnable (user branch)',
        "tests": ['tests/Feature/Security/OperatorRootedStoreListingsTest.php'],
        "mutants": [
            ('L1 switchableStores is the old predicate (creatorId())', [
                ('app/Models/User.php',
                 "        $root = \\App\\Policies\\UserPolicy::isOperator($this)\n            ? (int) $this->creatorId()\n            : \\App\\Policies\\UserPolicy::merchantTenantRoot($this);\n\n        if ($root === null || $root <= 0) {\n            return new \\Illuminate\\Database\\Eloquent\\Collection();\n        }\n\n        return Store::where('is_active', 1)\n            ->where('created_by', $root)\n",
                 "        return Store::where('is_active', 1)\n            ->where('created_by', $this->creatorId())\n"),
            ]),
            ('L2 switchableStores forgets the operator-first limb (the operator loses their list)', [
                ('app/Models/User.php',
                 '$root = \\App\\Policies\\UserPolicy::isOperator($this)\n            ? (int) $this->creatorId()\n            : \\App\\Policies\\UserPolicy::merchantTenantRoot($this);',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($this);'),
            ]),
            ('L3 switchableStores has no merchant test (creatorId() for everyone but the operator)', [
                ('app/Models/User.php',
                 '            : \\App\\Policies\\UserPolicy::merchantTenantRoot($this);\n\n        if ($root === null || $root <= 0) {',
                 '            : (int) $this->creatorId();\n\n        if ($root === null || $root <= 0) {'),
            ]),
            ('L4 UserUnable user branch is the old predicate', [
                ('app/Http/Controllers/UserController.php',
                 '\t\t        $ownerId = \\App\\Policies\\UserPolicy::isOperator(auth()->user())\n\t\t            ? $target->creatorId()\n\t\t            : \\App\\Policies\\UserPolicy::merchantTenantRoot($target);\n',
                 '\t\t        $ownerId = $target->creatorId();\n'),
            ]),
            ('L5 UserUnable forgets the operator-first limb (the operator loses the counters)', [
                ('app/Http/Controllers/UserController.php',
                 '\t\t        $ownerId = \\App\\Policies\\UserPolicy::isOperator(auth()->user())\n\t\t            ? $target->creatorId()\n\t\t            : \\App\\Policies\\UserPolicy::merchantTenantRoot($target);\n',
                 '\t\t        $ownerId = \\App\\Policies\\UserPolicy::merchantTenantRoot($target);\n'),
            ]),
            ('L6 UserUnable calls the counter with a null owner (no empty structure)', [
                ('app/Http/Controllers/UserController.php',
                 "\t\t        $data = $ownerId === null\n\t\t            ? ['is_success' => true, 'response' => ['users_data' => [], 'store_data' => ['total_store' => 0, 'disable_store' => 0, 'active_store' => 0]]]\n\t\t            : $this->storeUserCounter($ownerId);\n",
                 '\t\t        $data = $this->storeUserCounter($ownerId);\n'),
            ]),
            ('L8 switchableStores asks only "is the root the operator" (staff of a demoted merchant keep their stores)', [
                ('app/Models/User.php',
                 '            : \\App\\Policies\\UserPolicy::merchantTenantRoot($this);\n\n        if ($root === null || $root <= 0) {',
                 '            : ((int) $this->creatorId() === 1 ? null : (int) $this->creatorId());\n\n        if ($root === null || $root <= 0) {'),
            ]),
            ('L9 UserUnable asks only "is the root the operator" (staff of a demoted merchant get the counters)', [
                ('app/Http/Controllers/UserController.php',
                 '            : \\App\\Policies\\UserPolicy::merchantTenantRoot($target);\n\n',
                 '            : ((int) $target->creatorId() === 1 ? null : $target->creatorId());\n\n'),
            ]),
            ('L7 UserUnable has no merchant test (creatorId() for every non-operator caller)', [
                ('app/Http/Controllers/UserController.php',
                 '            : \\App\\Policies\\UserPolicy::merchantTenantRoot($target);\n\n',
                 '            : $target->creatorId();\n\n'),
            ]),
        ],
    },
    {
        "key": 'writer',
        "title": 'P1-SEC-67c -- SettingOwnership::keep() (the settings write hook)',
        "tests": ['tests/Feature/Security/OperatorRootedSettingsWritesTest.php', 'tests/Feature/Security/SettingsCreatedByOwnershipTest.php'],
        "mutants": [
            ('W1 the old predicate (creatorId())', [
                ('app/Services/SettingOwnership.php',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($user);',
                 '$root = $user->creatorId();'),
            ]),
            ('W2 never re-stamp (the P1-PLT-07 normalisation removed)', [
                ('app/Services/SettingOwnership.php',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($user);',
                 '$root = null;'),
            ]),
            ('W3 only the operator id is refused (the narrower question)', [
                ('app/Services/SettingOwnership.php',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($user);',
                 '$root = (int) $user->creatorId() > 1 ? (int) $user->creatorId() : null;'),
            ]),
            ('W4 the hook re-stamps to the operator for a non-merchant root explicitly', [
                ('app/Services/SettingOwnership.php',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($user);',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($user) ?? 1;'),
            ]),
            ("W5 the merchant test applies to the writer's CREATOR instead of the writer", [
                ('app/Services/SettingOwnership.php',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot($user);',
                 '$root = \\App\\Policies\\UserPolicy::merchantTenantRoot(\\App\\Models\\User::find((int) $user->created_by) ?? $user);'),
            ]),
        ],
    },
    {
        "key": 'sec65',
        "title": 'P1-SEC-65 (withdrawn) -- ModuleOrderMutationGuard already guards the PDFVouchers coupon-request route',
        "tests": ['tests/Feature/Security/AnonymousAdministrativeModuleRoutesTest.php'],
        "mutants": [
            ('G1 the guard no longer has a target for coupencode/Status/store', [
                ('app/Http/Middleware/ModuleOrderMutationGuard.php',
                 "        'coupencode/Status/store' => [",
                 "        'coupencode/Status/store-MUTANT-NO-MATCH' => ["),
            ]),
        ],
    },
    {
        "key": 'detect',
        "title": 'The read-only detection queries the owner is asked to run (detect-A to detect-D): the SQL in the work queue is mutated, and the test that executes the document must notice',
        "tests": ['tests/Feature/Security/StalePointerDetectionQueryTest.php'],
        "mutants": [
            ('Q1 detect-A joins any store owner, not only the operator', [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 "JOIN stores s ON s.id = u.current_store\nJOIN users op ON op.id = s.created_by AND op.type = 'super admin'\nWHERE u.type <> 'super admin';",
                 "JOIN stores s ON s.id = u.current_store\nJOIN users op ON op.id = s.created_by\nWHERE u.type <> 'super admin';"),
            ]),
            ('Q2 detect-B compares the store owner with created_by for everyone (a merchant in their own store is reported)', [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 "AND s.created_by <> CASE WHEN u.type = 'admin' THEN u.id ELSE u.created_by END;",
                 'AND s.created_by <> u.created_by;'),
            ]),
            ('Q3 detect-C INNER JOINs stores again (its first form: a row in no store is invisible)', [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 'LEFT JOIN stores st ON st.id = s.store_id',
                 'JOIN stores st ON st.id = s.store_id'),
            ]),
            ('Q4 detect-C drops the no-store limb', [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 'WHERE st.id IS NULL OR st.created_by <> s.created_by',
                 'WHERE st.created_by <> s.created_by'),
            ]),
            ("Q5 detect-C reports every operator-stamped row, the platform's own included", [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 'WHERE st.id IS NULL OR st.created_by <> s.created_by',
                 'WHERE 1 = 1'),
            ]),
            ("Q6 detect-D forgets the employer's type (only a missing employer is listed)", [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 "AND (c.id IS NULL OR c.type IS NULL OR c.type <> 'admin')",
                 'AND c.id IS NULL'),
            ]),
            ('Q7 detect-D asks only whether the employer is the operator (staff of a demoted merchant are missed)', [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 "AND (c.id IS NULL OR c.type IS NULL OR c.type <> 'admin')",
                 "AND c.type = 'super admin'"),
            ]),
            ('Q8 detect-D lets the operator onto the list', [
                ('docs/security/P01-SECURITY-WORK-QUEUE.md',
                 "WHERE u.type NOT IN ('admin', 'super admin')",
                 "WHERE u.type <> 'admin'"),
            ]),
            ("Q9 the settings helper is the old predicate again (a principal on detect-D's list is served the operator's row)", [
                ('app/Helper/helper.php',
                 '$creator = User::find($user->created_by);\n\n                if (! \\App\\Policies\\UserPolicy::isMerchantRow($creator)) {\n                    return [];\n                }\n\n                $user = $creator;',
                 '$user = User::find($user->created_by);'),
            ]),
        ],
    },
    {
        "key": 'upload',
        "title": "P1-SEC-67d -- StoredUpload rule 3: the operator's cross-tenant folder exemption belongs to the operator acting, not to the operator's id",
        "tests": ['tests/Feature/Security/OperatorRootedStoredUploadTest.php', 'tests/Feature/Security/StoredUploadTest.php'],
        "mutants": [
            ('U1 the exemption keys on the owner id alone again (the fix reverted)', [
                ('app/Services/StoredUpload.php',
                 "            $actor = auth('web')->user();\n\n            if ($actor instanceof User && ! UserPolicy::isOperator($actor)) {\n                return Store::where('id', $storeId)->where('created_by', $actor->id)->exists();\n            }\n\n            return true;\n",
                 '            return true;\n'),
            ]),
            ("U2 a non-operator who presents the operator's id is given the stores of the id they presented (the operator's own)", [
                ('app/Services/StoredUpload.php',
                 "                return Store::where('id', $storeId)->where('created_by', $actor->id)->exists();\n",
                 "                return Store::where('id', $storeId)->where('created_by', $ownerId)->exists();\n"),
            ]),
            ("U3 a non-operator who presents the operator's id is refused every store folder, their own included", [
                ('app/Services/StoredUpload.php',
                 "                return Store::where('id', $storeId)->where('created_by', $actor->id)->exists();\n",
                 '                return false;\n'),
            ]),
            ('U4 the operator acting loses the exemption too', [
                ('app/Services/StoredUpload.php',
                 '            if ($actor instanceof User && ! UserPolicy::isOperator($actor)) {\n',
                 '            if ($actor instanceof User) {\n'),
            ]),
            ('U5 the actor is read from the DEFAULT guard with no User test (a storefront customer becomes a panel actor)', [
                ('app/Services/StoredUpload.php',
                 "            $actor = auth('web')->user();\n\n            if ($actor instanceof User && ! UserPolicy::isOperator($actor)) {\n",
                 '            $actor = auth()->user();\n\n            if ($actor !== null && ! UserPolicy::isOperator($actor)) {\n'),
            ]),
            ('U6 the uniform rule instead: every panel user whose root is not a merchant is refused every store folder', [
                ('app/Services/StoredUpload.php',
                 '        $owner = User::find($ownerId);\n\n        if (UserPolicy::isOperator($owner)) {\n',
                 "        $panel = auth('web')->user();\n        if ($panel instanceof User && ! UserPolicy::isOperator($panel) && UserPolicy::merchantTenantRoot($panel) === null) {\n            return false;\n        }\n\n        $owner = User::find($ownerId);\n\n        if (UserPolicy::isOperator($owner)) {\n"),
            ]),
            ('U7 only a presented id of exactly 1 loses the exemption (an operator row with another id keeps handing it out)', [
                ('app/Services/StoredUpload.php',
                 '            if ($actor instanceof User && ! UserPolicy::isOperator($actor)) {\n',
                 '            if ((int) $ownerId === 1 && $actor instanceof User && ! UserPolicy::isOperator($actor)) {\n'),
            ]),
            ('U8 the guard alone: the DEFAULT guard is asked, the User test kept (a panel session behind a storefront request is not seen)', [
                ('app/Services/StoredUpload.php',
                 "            $actor = auth('web')->user();\n",
                 '            $actor = auth()->user();\n'),
            ]),
            ('U9 the exemption is for the OWNER acting, not for an operator acting (a second operator account loses it)', [
                ('app/Services/StoredUpload.php',
                 '            if ($actor instanceof User && ! UserPolicy::isOperator($actor)) {\n',
                 '            if ($actor instanceof User && (int) $actor->id !== (int) $ownerId) {\n'),
            ]),
            ('U10 an owner id that names no user enters the operator branch', [
                ('app/Services/StoredUpload.php',
                 '        if (UserPolicy::isOperator($owner)) {\n',
                 '        if ($owner === null || UserPolicy::isOperator($owner)) {\n'),
            ]),
            ('U11 the owner test is its own strtolower(trim()) again (a leading-space lookalike row is an operator)', [
                ('app/Services/StoredUpload.php',
                 '        if (UserPolicy::isOperator($owner)) {\n',
                 "        if ($owner !== null && strtolower(trim((string) $owner->type)) === 'super admin') {\n"),
            ]),
        ],
    },
    {
        "key": 'themeimage',
        "title": "P1-SEC-72 -- upload_theme_image() stores images only, under the server's extension, in the store's folder; and its first-party callers",
        "tests": ['tests/Feature/Security/ThemeImageUploadTypeTest.php'],
        "mutants": [
            # ------------------------------------------------------------------------------------------------ the two type locks
            ("I1 helper: the stored extension is the client's again", [
                ('app/Helper/helper.php',
                 '        $extension = $refusal === null ? \\App\\Services\\ThemeImageUpload::storedExtension($theme_image, (bool) $for_customer) : null;',
                 '        $extension = $refusal === null ? strtolower($theme_image->getClientOriginalExtension()) : null;'),
            ]),
            ('I2 rules: `mimes:` removed (the name lock and the stored-extension lock are still there)', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['mimes:' . $types, 'extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],"),
            ]),
            ("I3 storedExtension(): reads the client's name instead of the bytes", [
                ('app/Services/ThemeImageUpload.php',
                 '        $guessed = strtolower((string) $file->guessExtension());',
                 '        $guessed = strtolower((string) $file->getClientOriginalExtension());'),
            ]),
            ('I4 both type locks gone: no `mimes:` and the name decides the extension', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['mimes:' . $types, 'extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],"),
                ('app/Services/ThemeImageUpload.php',
                 '        $guessed = strtolower((string) $file->guessExtension());',
                 '        $guessed = strtolower((string) $file->getClientOriginalExtension());'),
            ]),
            ('I5 rules: `extensions:` removed', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['mimes:' . $types, 'extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['mimes:' . $types, 'max:' . self::maxKb($forCustomer)]],"),
            ]),
            ('I6 the customer list gains svg', [
                ('app/Services/ThemeImageUpload.php',
                 "    public const CUSTOMER_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];",
                 "    public const CUSTOMER_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];"),
            ]),
            ('I7 the panel list loses svg', [
                ('app/Services/ThemeImageUpload.php',
                 "    public const PANEL_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];",
                 "    public const PANEL_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];"),
            ]),
            ('I8 avatar handler: not marked as a customer upload', [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->id, true);',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->id, false);'),
            ]),
            ('I9 avatar handler: the store is not named', [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->id, true);',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, null, true);'),
            ]),
            ('I10 avatar handler: the refusal is not checked', [
                ('app/Http/Controllers/Api/ApiController.php',
                 "            if (empty($cover_image['status'])) {\n                // The state of the merchant's plan",
                 "            if (false) {\n                // The state of the merchant's plan"),
            ]),
            # ------------------------------------------------------------------------------------------------ decide, store, then charge
            ('I11 helper: the plan is charged before the file is looked at', [
                ('app/Helper/helper.php',
                 '        if (empty($theme_image)) {\n            return $return;\n        }\n',
                 '        if (empty($theme_image)) {\n            return $return;\n        }\n        Utility::updateStorageLimit($user_id ?? \\Auth::user()->creatorId(), is_object($theme_image) ? $theme_image->getSize() : 0);\n'),
            ]),
            ('I12 helper: over the plan, the stored file is left on disk', [
                ('app/Helper/helper.php',
                 "            $disk->delete($save);\n            $return['message'] = __('Plan storage limit is over so please upgrade the plan.');\n",
                 "            $return['message'] = __('Plan storage limit is over so please upgrade the plan.');\n"),
            ]),
            ('I13 helper: a failed write is reported as success', [
                ('app/Helper/helper.php',
                 "        if (! is_string($save) || $save === '') {",
                 '        if (false) {'),
            ]),
            ('I14 helper: the name prefix is not sanitised', [
                ('app/Helper/helper.php',
                 "preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key)",
                 '((string) $key)'),
            ]),
            ('I15 helper: rand(0, 100) again instead of random bytes', [
                ('app/Helper/helper.php',
                 'bin2hex(random_bytes(6))',
                 'rand(0, 100)'),
            ]),
            ('I16 rules: `max:` removed', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['mimes:' . $types, 'extensions:' . implode(',', self::clientNameExtensions($forCustomer)), 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['mimes:' . $types, 'extensions:' . implode(',', self::clientNameExtensions($forCustomer))]],"),
            ]),
            ('I17 helper: the named store is ignored (the panel guard decides the folder)', [
                ('app/Helper/helper.php',
                 '        $folder = (int) $store_id > 0 ? (int) $store_id : getCurrentStore();',
                 '        $folder = getCurrentStore();'),
            ]),
            # ------------------------------------------------------------------------------------------------ the web payment settings
            ('I18 web payment settings: a refused image is not recorded', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 "            $this->refusedImages[$gatewayId !== '' ? $gatewayId : $imageKey] = (string) $uploadResult['message'];\n",
                 ''),
            ]),
            ('I19 web payment settings: the refusal list is not reset per request', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 '        $this->refusedImages = [];\n\n        // Handle currency settings',
                 '        // Handle currency settings'),
            ]),
            ('I20 rules: the not-an-uploaded-file pre-check removed (a string would reach `extensions:`)', [
                ('app/Services/ThemeImageUpload.php',
                 "        if (! $file instanceof UploadedFile) {\n            return (string) str_replace(':attribute', $attribute, trans('validation.file'));\n        }\n\n",
                 ''),
            ]),
            ('I21 the customer ceiling is raised (in maxKb)', [
                ('app/Services/ThemeImageUpload.php',
                 '        return $forCustomer ? self::CUSTOMER_MAX_KB : self::PANEL_MAX_KB;',
                 '        return $forCustomer ? 999999 : self::PANEL_MAX_KB;'),
            ]),
            ('I22 helper: the folder keeps its trailing slash', [
                ('app/Helper/helper.php',
                 "        $dir = rtrim('uploads/' . $folder, '/');",
                 "        $dir = 'uploads/' . $folder;"),
            ]),
            ('I23 avatar handler: a refusal answers 401', [
                ('app/Http/Controllers/Api/ApiController.php',
                 "                return $this->error(['message' => $message], $message);",
                 "                return $this->error(['message' => $message], $message, 401);"),
            ]),
            ('I24 storedExtension(): the list is not consulted', [
                ('app/Services/ThemeImageUpload.php',
                 "        return $guessed !== '' && in_array($guessed, self::types($forCustomer), true) ? $guessed : null;",
                 "        return $guessed !== '' ? $guessed : null;"),
            ]),
            ('I25 avatar handler: the replaced avatar is unlinked without a credit', [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            Utility::changeStorageLimit($store->created_by, $previous);',
                 '            \\App\\Services\\StoredUpload::delete($previous, $store->created_by);'),
            ]),
            # ------------------------------------------------------------------------------------------------ the refusal log line
            ('I26 helper: a refusal is not logged', [
                ('app/Helper/helper.php',
                 "            \\Illuminate\\Support\\Facades\\Log::warning('an image upload was refused', \\App\\Services\\ThemeImageUpload::describe($theme_image) + [",
                 "            (fn (...$x) => null)('an image upload was refused', \\App\\Services\\ThemeImageUpload::describe($theme_image) + ["),
            ]),
            ("I27 describe(): the log carries the client's file name", [
                ('app/Services/ThemeImageUpload.php',
                 "            'sent' => 'file',\n",
                 "            'sent' => 'file',\n            'name' => $file->getClientOriginalName(),\n"),
            ]),
            ('I28 helper: the type rule is skipped altogether (refusal() never consulted)', [
                ('app/Helper/helper.php',
                 '        $refusal = \\App\\Services\\ThemeImageUpload::refusal($theme_image, (bool) $for_customer);',
                 '        $refusal = null;'),
            ]),
            # ------------------------------------------------------------------------------------------------ added by the review (tests lens T-01..T-14, bypass, regression)
            ('I29 helper: with no store named the folder is the acting USER\'s id (not the current store)', [
                ('app/Helper/helper.php',
                 '        $folder = (int) $store_id > 0 ? (int) $store_id : getCurrentStore();',
                 '        $folder = (int) $store_id > 0 ? (int) $store_id : \\Auth::id();'),
            ]),
            ('I30 helper: a folder it creates is left at the disk\'s private mode', [
                ('app/Helper/helper.php',
                 '            @chmod($disk->path($dir), 0755);\n',
                 ''),
            ]),
            ('I31 helper: a charge that throws leaves the stored file behind', [
                ('app/Helper/helper.php',
                 '            $disk->delete($save);\n\n            throw $e;\n',
                 '            throw $e;\n'),
            ]),
            ("I32 helper: a plan refusal is reported as a type refusal (the avatar handler cannot tell them apart)", [
                ('app/Helper/helper.php',
                 "            $return['reason'] = 'plan';\n",
                 "            $return['reason'] = 'invalid';\n"),
            ]),
            ('I33 describe(): an upload PHP itself refused is treated as readable', [
                ('app/Services/ThemeImageUpload.php',
                 '        $valid = $file->isValid();',
                 '        $valid = true;'),
            ]),
            ('I34 helper: the random part of the name is a constant', [
                ('app/Helper/helper.php',
                 'bin2hex(random_bytes(6))',
                 "'000000000000'"),
            ]),
            ('I35 the customer ceiling constant is 8192', [
                ('app/Services/ThemeImageUpload.php',
                 '    public const CUSTOMER_MAX_KB = 2048;',
                 '    public const CUSTOMER_MAX_KB = 8192;'),
            ]),
            ("I36 the panel's ceiling constant is the customer's", [
                ('app/Services/ThemeImageUpload.php',
                 '    public const PANEL_MAX_KB = 2048000;',
                 '    public const PANEL_MAX_KB = 2048;'),
            ]),
            ("I37 describe(): the log carries the client's own extension text", [
                ('app/Services/ThemeImageUpload.php',
                 "            'client_extension' => $named === '' ? 'none' : (in_array($named, self::LOGGED_EXTENSIONS, true) ? $named : 'other'),",
                 "            'client_extension' => $named,"),
            ]),
            ('I38 the JPEG name aliases (.jfif, .jpe) are not accepted', [
                ('app/Services/ThemeImageUpload.php',
                 '        return array_merge(self::types($forCustomer), self::JPEG_NAME_ALIASES);',
                 '        return self::types($forCustomer);'),
            ]),
            ('I39 avatar handler: the previous avatar is unlinked and credited BEFORE the upload is judged', [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->id, true);\n',
                 '            Utility::changeStorageLimit($store->created_by, $user->profile_image);\n            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->id, true);\n'),
            ]),
            ("I40 avatar handler: the credit goes to the CUSTOMER's id, not the store owner's", [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            Utility::changeStorageLimit($store->created_by, $previous);',
                 '            Utility::changeStorageLimit($user->id, $previous);'),
            ]),
            ("I41 avatar handler: the store's folder is the owner's USER id", [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->id, true);',
                 '            $cover_image = upload_theme_image($theme_image, 0, $store->created_by, $store->created_by, true);'),
            ]),
            ('I42 avatar handler: the previous avatar is unlinked before the new path is saved', [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            $previous = $user->profile_image;\n            $user->profile_image = $cover_image[\'image_path\'];\n',
                 '            $previous = $user->profile_image;\n            Utility::changeStorageLimit($store->created_by, $previous);\n            $user->profile_image = $cover_image[\'image_path\'];\n'),
            ]),
            ("I43 avatar handler: the plan's state is told to the shopper", [
                ('app/Http/Controllers/Api/ApiController.php',
                 "                $message = ($cover_image['reason'] ?? '') === 'plan' ? __('The image could not be saved.') : $cover_image['message'];",
                 "                $message = $cover_image['message'];"),
            ]),
            ('I44 avatar handler: a save that fails leaves the new file stored and charged', [
                ('app/Http/Controllers/Api/ApiController.php',
                 "                Utility::changeStorageLimit($store->created_by, $cover_image['image_path']);   // not kept: unlink it, hand its charge back\n\n                throw $e;",
                 '                throw $e;'),
            ]),
            ("I45 admin API cod-payment: the store the handler authorised is not named", [
                ('app/Http/Controllers/Api/DashboardController.php',
                 '                $theme_image = $request->cod_image;\n                $image = upload_theme_image($theme_image, 0, 0, $store->id);',
                 '                $theme_image = $request->cod_image;\n                $image = upload_theme_image($theme_image);'),
            ]),
            ("I46 admin API bank-payment: the store the handler authorised is not named", [
                ('app/Http/Controllers/Api/DashboardController.php',
                 '                $theme_image = $request->bank_transfer_image;\n                $image = upload_theme_image($theme_image, 0, 0, $store->id);',
                 '                $theme_image = $request->bank_transfer_image;\n                $image = upload_theme_image($theme_image);'),
            ]),
            ("I47 admin API stripe-payment: the store the handler authorised is not named", [
                ('app/Http/Controllers/Api/DashboardController.php',
                 '                $theme_image = $request->stripe_image;\n                $image = upload_theme_image($theme_image, 0, 0, $store->id);',
                 '                $theme_image = $request->stripe_image;\n                $image = upload_theme_image($theme_image);'),
            ]),
            ('I48 admin API cod-payment: a refusal answers in the old, bare shape', [
                ('app/Http/Controllers/Api/DashboardController.php',
                 "                $theme_image = $request->cod_image;\n                $image = upload_theme_image($theme_image, 0, 0, $store->id);\n                if ($image['status'] == false) {\n                    return $this->error(['message' => $image['message']], $image['message']);",
                 "                $theme_image = $request->cod_image;\n                $image = upload_theme_image($theme_image, 0, 0, $store->id);\n                if ($image['status'] == false) {\n                    return $this->error($image['message']);"),
            ]),
            ('I49 web payment settings: the written-keys list is not reset per request', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 '        $this->post = [];\n        $this->incomplete = [];\n        $this->refusedImages = [];\n',
                 '        $this->incomplete = [];\n        $this->refusedImages = [];\n'),
            ]),
            ('I50 web payment settings: the incomplete-gateways list is not reset per request', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 '        $this->post = [];\n        $this->incomplete = [];\n        $this->refusedImages = [];\n',
                 '        $this->post = [];\n        $this->refusedImages = [];\n'),
            ]),
            ('I51 web payment settings: the missing-field message names a gateway by `__($id)` (an array for myfatoorah)', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 "                return $this->gatewayDisplayName($row['gateway']) . ' (' . implode(', ', array_map(",
                 "                return __($row['gateway']) . ' (' . implode(', ', array_map("),
            ]),
            ('I52 web payment settings: the refused-image message names a gateway by `__($id)`', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 "                $rows[] = $this->gatewayDisplayName((string) $gatewayId) . ' (' . $reason . ')';",
                 "                $rows[] = __($gatewayId) . ' (' . $reason . ')';"),
            ]),
            ('I54 web payment settings: the store whose row is written is not named for the image', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 '        $uploadResult = upload_theme_image($image, 0, 0, $this->store_id);',
                 '        $uploadResult = upload_theme_image($image);'),
            ]),
            ('I53 web payment settings: the two sentences of one message are run together', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 "            return redirect()->back()->with('error', implode('. ', $problems));",
                 "            return redirect()->back()->with('error', implode(' ', $problems));"),
            ]),
        ],
    },
    {
        "key": 'platformuploader',
        "title": "P1-SEC-73 -- the platform uploader's four functions judge the name as well as the bytes (PlatformUploadPolicy): the shopper rule follows the route, the lists are read once as plain words, four doors name their own types or build their own names",
        "tests": ['tests/Feature/Security/PlatformUploaderTypeTest.php', 'tests/Feature/Security/PlatformUploaderDoorsTest.php'],
        "mutants": [
            ('PU1 policy: a value that is not a valid upload is not refused as one', [
                ('app/Services/PlatformUploadPolicy.php',
                 '        if (! $file instanceof UploadedFile || ! $file->isValid()) {',
                 '        if (false) {'),
            ]),
            ('PU2 policy: an empty configured list is not refused as an empty list', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            $allowed = self::allowed($configured, $panel);\n\n            if ($allowed === []) {',
                 '            $allowed = self::allowed($configured, $panel);\n\n            if (false) {'),
            ]),
            ("PU3 policy: a caller's own list that is cleaned to nothing is not refused", [
                ('app/Services/PlatformUploadPolicy.php',
                 '            $allowed = $fromExtensions ?? $fromMimes;\n\n            if ($allowed === []) {',
                 '            $allowed = $fromExtensions ?? $fromMimes;\n\n            if (false) {'),
            ]),
            ("PU4 policy: a caller's own rule array may drop the size ceiling", [
                ('app/Services/PlatformUploadPolicy.php',
                 "            if (! self::carries($rules, 'max:')) {",
                 '            if (false) {'),
            ]),
            ("PU5 policy: a caller's `extensions:` list is passed on as the caller wrote it", [
                ('app/Services/PlatformUploadPolicy.php',
                 "                    $rule = 'extensions:' . implode(',', $fromExtensions);",
                 '                    $rule = $rule;'),
            ]),
            ("PU6 policy: a caller's `mimes:` list is passed on as the caller wrote it", [
                ('app/Services/PlatformUploadPolicy.php',
                 "                    $rule = 'mimes:' . implode(',', $fromMimes);",
                 '                    $rule = $rule;'),
            ]),
            ("PU7 policy: the stored name is held to the caller's `mimes:` list before its `extensions:` list", [
                ('app/Services/PlatformUploadPolicy.php',
                 '            $allowed = $fromExtensions ?? $fromMimes;',
                 '            $allowed = $fromMimes ?? $fromExtensions;'),
            ]),
            ('PU8 policy: an empty stored name is accepted', [
                ('app/Services/PlatformUploadPolicy.php',
                 "$name === '' || ",
                 ''),
            ]),
            ('PU9 policy: a stored name with a path, a backslash or a control character is accepted (by the byte rule)', [
                ('app/Services/PlatformUploadPolicy.php',
                 "preg_match('/[\\x00-\\x1F\\x7F\\/\\\\\\\\]/', $name) === 1 || ",
                 ''),
            ]),
            ('PU10 policy: a stored name with a format character (a right-to-left mark) is accepted', [
                ('app/Services/PlatformUploadPolicy.php',
                 " || preg_match('/\\p{C}/u', $name) === 1",
                 ''),
            ]),
            ('PU11 policy: a stored name with no dot is read as its own extension', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        $final = count($segments) >= 2 ? strtolower((string) end($segments)) : '';",
                 '        $final = strtolower((string) end($segments));'),
            ]),
            ('PU12 policy: the stored extension is not lower-cased', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        $final = count($segments) >= 2 ? strtolower((string) end($segments)) : '';",
                 "        $final = count($segments) >= 2 ? (string) end($segments) : '';"),
            ]),
            ("PU13 policy: a script word as the name's last extension is accepted", [
                ('app/Services/PlatformUploadPolicy.php',
                 '        if (self::isScriptWord($final)) {',
                 '        if (false) {'),
            ]),
            ('PU14 policy: a handler word INSIDE the stored name is not looked for', [
                ('app/Services/PlatformUploadPolicy.php',
                 '        foreach (array_slice($segments, 1, -1) as $inner) {',
                 '        foreach ([] as $inner) {'),
            ]),
            ('PU15 policy: the base of a name is judged as an inner extension too (a logo called js.png)', [
                ('app/Services/PlatformUploadPolicy.php',
                 'array_slice($segments, 1, -1)',
                 'array_slice($segments, 0, -1)'),
            ]),
            ('PU16 policy: an inner extension is compared without lower-casing it', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            if (self::isHandlerWord(strtolower($inner))) {',
                 '            if (self::isHandlerWord($inner)) {'),
            ]),
            ('PU17 policy: an inner extension is held to the whole script list (the first form: Amazon.com.png refused)', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return in_array($word, self::INNER_HANDLED, true) || preg_match('/^php\\d*$/', $word) === 1;",
                 "        return in_array($word, self::SCRIPT_CAPABLE, true) || preg_match('/^php\\d*$/', $word) === 1;"),
            ]),
            ('PU18 policy: the numbered php family is not matched as an inner handler', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return in_array($word, self::INNER_HANDLED, true) || preg_match('/^php\\d*$/', $word) === 1;",
                 '        return in_array($word, self::INNER_HANDLED, true);'),
            ]),
            ('PU19 policy: the numbered php family is not matched as a script word', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return in_array($word, self::SCRIPT_CAPABLE, true) || preg_match('/^php\\d*$/', $word) === 1;",
                 '        return in_array($word, self::SCRIPT_CAPABLE, true);'),
            ]),
            ('PU20 rules: `mimes:` removed', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $rules = ['mimes:' . $list, 'extensions:' . $list, 'max:' . $maxKb];",
                 "            $rules = ['extensions:' . $list, 'max:' . $maxKb];"),
            ]),
            ("PU21 rules: `extensions:` removed (the client's own name is not judged)", [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $rules = ['mimes:' . $list, 'extensions:' . $list, 'max:' . $maxKb];",
                 "            $rules = ['mimes:' . $list, 'max:' . $maxKb];"),
            ]),
            ('PU22 rules: `max:` removed', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $rules = ['mimes:' . $list, 'extensions:' . $list, 'max:' . $maxKb];",
                 "            $rules = ['mimes:' . $list, 'extensions:' . $list];"),
            ]),
            ('PU23 rules: built from the raw configured list, not the cleaned one', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $list = implode(',', $allowed);",
                 '            $list = $configured;'),
            ]),
            ("PU24 policy: the stored name's last extension need not be in the list", [
                ('app/Services/PlatformUploadPolicy.php',
                 '        if ($allowed !== null && ! in_array($final, $allowed, true)) {',
                 '        if (false) {'),
            ]),
            ("PU25 policy: the client's extension is not checked for a script, nor (for a shopper) an active document", [
                ('app/Services/PlatformUploadPolicy.php',
                 '            if (self::isScriptWord($word) || (! $panel && in_array($word, self::SHOPPER_DENIED, true))) {',
                 '            if (false) {'),
            ]),
            ("PU26 policy: a shopper is not refused an active document by the client's extension", [
                ('app/Services/PlatformUploadPolicy.php',
                 ' || (! $panel && in_array($word, self::SHOPPER_DENIED, true))) {',
                 ') {'),
            ]),
            ("PU27 policy: rules that name no list do not examine the stored name's extension", [
                ('app/Services/PlatformUploadPolicy.php',
                 '        foreach ($allowed === null ? [strtolower($file->getClientOriginalExtension()), $final] : [strtolower($file->getClientOriginalExtension())] as $word) {',
                 '        foreach ([strtolower($file->getClientOriginalExtension())] as $word) {'),
            ]),
            ('PU28 allowed(): jpg and jpeg no longer travel together', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $list = array_merge($list, ['jpg', 'jpeg'], $jpegAliases ? self::JPEG_ALIASES : []);",
                 '            $list = array_merge($list, $jpegAliases ? self::JPEG_ALIASES : []);'),
            ]),
            ('PU29 allowed(): jpe and jfif do not travel with jpg and jpeg', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $list = array_merge($list, ['jpg', 'jpeg'], $jpegAliases ? self::JPEG_ALIASES : []);",
                 "            $list = array_merge($list, ['jpg', 'jpeg']);"),
            ]),
            ('PU30 allowed(): an entry that is not a plain word reaches the rules', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            if (preg_match('/^[a-z0-9]{1,12}$/', $word) === 1) {",
                 "            if ($word !== '') {"),
            ]),
            ("PU31 allowed(): a shopper's list is the panel's (an SVG is stored by a visitor)", [
                ('app/Services/PlatformUploadPolicy.php',
                 '            static fn (string $word) => ! self::isScriptWord($word) && ($panelSession || ! in_array($word, self::SHOPPER_DENIED, true))',
                 '            static fn (string $word) => ! self::isScriptWord($word)'),
            ]),
            ('PU32 allowed(): a script word survives the list', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            static fn (string $word) => ! self::isScriptWord($word) && ($panelSession || ! in_array($word, self::SHOPPER_DENIED, true))',
                 '            static fn (string $word) => ($panelSession || ! in_array($word, self::SHOPPER_DENIED, true))'),
            ]),
            ('PU33 panelSession(): a request with no web user is not refused the panel list by the first test', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            if (! auth('web')->check()) {\n                return false;\n            }",
                 '            if (false) {\n                return false;\n            }'),
            ]),
            ('PU34 panelSession(): the route is not asked (the first form: any web login is a panel request)', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            return $route instanceof Route ? self::routeDemandsAPanelUser($route) : true;',
                 '            return true;'),
            ]),
            ('PU35 panelSession(): with no router a web user is a shopper', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            return $route instanceof Route ? self::routeDemandsAPanelUser($route) : true;',
                 '            return $route instanceof Route ? self::routeDemandsAPanelUser($route) : false;'),
            ]),
            ('PU36 panelSession(): a guard that throws is a panel session', [
                ('app/Services/PlatformUploadPolicy.php',
                 '        } catch (\\Throwable $e) {\n            return false;',
                 '        } catch (\\Throwable $e) {\n            return true;'),
            ]),
            ("PU37 routeDemandsAPanelUser(): `custom.auth` is not a panel route (a merchant's token is a shopper)", [
                ('app/Services/PlatformUploadPolicy.php',
                 "            if ($name === 'custom.auth' || ((",
                 '            if ((('),
            ]),
            ('PU38 routeDemandsAPanelUser(): `auth:<any guard>` is a panel route', [
                ('app/Services/PlatformUploadPolicy.php',
                 "($guards === '' || $guards === 'web')",
                 'true'),
            ]),
            ('PU39 refused(): a refusal is not logged', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        Log::warning('a platform upload was refused', [",
                 "        (fn (...$x) => null)('a platform upload was refused', ["),
            ]),
            ("PU40 refused(): the log carries the client's own extension text", [
                ('app/Services/PlatformUploadPolicy.php',
                 'ThemeImageUpload::extensionWord($file->getClientOriginalExtension()) : null,',
                 'strtolower($file->getClientOriginalExtension()) : null,'),
            ]),
            ("PU41 refused(): the stored name's extension refusal is logged as a name refusal", [
                ('app/Services/PlatformUploadPolicy.php',
                 "return self::refused('extension', $file,",
                 "return self::refused('name', $file,"),
            ]),
            ('PU42 receiptName(): no random part (two shoppers in one second share a file)', [
                ('app/Services/PlatformUploadPolicy.php',
                 ". '_' . bin2hex(random_bytes(4)) . '.'",
                 ". '.'"),
            ]),
            ("PU43 receiptName(): the client's punctuation is kept", [
                ('app/Services/PlatformUploadPolicy.php',
                 "preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))",
                 'pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)'),
            ]),
            ('PU44 receiptName(): the extension is not lower-cased', [
                ('app/Services/PlatformUploadPolicy.php',
                 "'.' . strtolower($file->getClientOriginalExtension());\n    }",
                 "'.' . $file->getClientOriginalExtension();\n    }"),
            ]),
            ('PU45 imageRules(): what a door adds is ignored', [
                ('app/Services/PlatformUploadPolicy.php',
                 'array_merge(self::IMAGE_TYPES, $more)',
                 'self::IMAGE_TYPES'),
            ]),
            ("PU46 imageRules(): the client's name is not held to the types", [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return ['mimes:' . $list, 'extensions:' . $list];",
                 "        return ['mimes:' . $list];"),
            ]),
            ('PU47 IMAGE_TYPES loses webp', [
                ('app/Services/PlatformUploadPolicy.php',
                 "['jpg', 'jpeg', 'jpe', 'jfif', 'png', 'gif', 'webp']",
                 "['jpg', 'jpeg', 'jpe', 'jfif', 'png', 'gif']"),
            ]),
            ('PU48 upload_file: the policy is not asked', [
                ('app/Models/Utility.php',
                 "                // P1-SEC-73 (upload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = \\App\\Services\\PlatformUploadPolicy::refusal($file, (string) $name, (string) $mimes, $max_size, $custom_validation);\n",
                 "                // P1-SEC-73 (upload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = null;\n"),
            ]),
            ('PU49 upload_file: the policy is asked only when no sixth argument is given (the old hole)', [
                ('app/Models/Utility.php',
                 "                // P1-SEC-73 (upload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = \\App\\Services\\PlatformUploadPolicy::refusal($file, (string) $name, (string) $mimes, $max_size, $custom_validation);\n",
                 "                // P1-SEC-73 (upload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = empty($image) ? \\App\\Services\\PlatformUploadPolicy::refusal($file, (string) $name, (string) $mimes, $max_size, $custom_validation) : null;\n"),
            ]),
            ('PU50 keyWiseUpload_file: the policy is not asked', [
                ('app/Models/Utility.php',
                 "                // P1-SEC-73 (keyWiseUpload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = \\App\\Services\\PlatformUploadPolicy::refusal($multifile[$key_name], (string) $name, (string) $mimes, $max_size, $custom_validation);\n",
                 "                // P1-SEC-73 (keyWiseUpload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = null;\n"),
            ]),
            ('PU51 jsonUpload_file: the policy is not asked', [
                ('app/Models/Utility.php',
                 "                // P1-SEC-73 (jsonUpload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = \\App\\Services\\PlatformUploadPolicy::refusal($file, (string) $name, (string) $mimes, $max_size, $custom_validation);\n",
                 "                // P1-SEC-73 (jsonUpload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = null;\n"),
            ]),
            ('PU52 multi_upload_file: the policy is not asked', [
                ('app/Helper/helper.php',
                 "                // P1-SEC-73 (multi_upload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = \\App\\Services\\PlatformUploadPolicy::refusal($file, (string) $name, (string) $mimes, $max_size, $custom_validation);\n",
                 "                // P1-SEC-73 (multi_upload_file): ONE decision for every caller -- see App\\Services\\PlatformUploadPolicy. This was `mimes:<list>` and `max:` alone, built only\n                // `if (empty($image))`: the sixth argument skipped it, the CLIENT'S extension was never looked at (the stored name keeps it, and a\n                // server acts on the name), and an empty list parsed to `mimes:[null]` and passed everything.\n                $refusal = null;\n"),
            ]),
            ('PU53 upload_file: a failed write is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (upload_file): `putFileAs()` answers false when the write fails, and this reported success with a URL for a file that was never written.\n                        if (! is_string($written) || $written === '') {",
                 '                        // P1-SEC-73 (upload_file): `putFileAs()` answers false when the write fails, and this reported success with a URL for a file that was never written.\n                        if (false) {'),
            ]),
            ('PU54 jsonUpload_file: a failed write is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (jsonUpload_file): `putFileAs()` answers false when the write fails, and this reported success with a URL for a file that was never written.\n                        if (! is_string($written) || $written === '') {",
                 '                        // P1-SEC-73 (jsonUpload_file): `putFileAs()` answers false when the write fails, and this reported success with a URL for a file that was never written.\n                        if (false) {'),
            ]),
            ('PU55 keyWiseUpload_file: a failed write is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (keyWiseUpload_file): a failed write is a failure, not a URL for a file that does not exist.\n                        if (! is_string($written) || $written === '') {",
                 '                        // P1-SEC-73 (keyWiseUpload_file): a failed write is a failure, not a URL for a file that does not exist.\n                        if (false) {'),
            ]),
            ('PU56 multi_upload_file: a failed write is reported as success', [
                ('app/Helper/helper.php',
                 "                    if (! is_string($save) || $save === '') {",
                 '                    if (false) {'),
            ]),
            ('PU57 upload_file: a failed write on wasabi is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (upload_file, wasabi): the remote branches check the write as the local one does.\n                        if (! is_string($path) || $path === '') {",
                 '                        // P1-SEC-73 (upload_file, wasabi): the remote branches check the write as the local one does.\n                        if (false) {'),
            ]),
            ('PU58 upload_file: a failed write on s3 is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (upload_file, s3): the remote branches check the write as the local one does.\n                        if (! is_string($path) || $path === '') {",
                 '                        // P1-SEC-73 (upload_file, s3): the remote branches check the write as the local one does.\n                        if (false) {'),
            ]),
            ('PU59 jsonUpload_file: a failed write on wasabi is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (jsonUpload_file, wasabi): the remote branches check the write as the local one does.\n                        if (! is_string($path) || $path === '') {",
                 '                        // P1-SEC-73 (jsonUpload_file, wasabi): the remote branches check the write as the local one does.\n                        if (false) {'),
            ]),
            ('PU60 jsonUpload_file: a failed write on s3 is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (jsonUpload_file, s3): the remote branches check the write as the local one does.\n                        if (! is_string($path) || $path === '') {",
                 '                        // P1-SEC-73 (jsonUpload_file, s3): the remote branches check the write as the local one does.\n                        if (false) {'),
            ]),
            ('PU61 keyWiseUpload_file: a failed write on wasabi is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (keyWiseUpload_file, wasabi): the remote branches check the write as the local one does.\n                        if (! is_string($path) || $path === '') {",
                 '                        // P1-SEC-73 (keyWiseUpload_file, wasabi): the remote branches check the write as the local one does.\n                        if (false) {'),
            ]),
            ('PU62 keyWiseUpload_file: a failed write on s3 is reported as success', [
                ('app/Models/Utility.php',
                 "                        // P1-SEC-73 (keyWiseUpload_file, s3): the remote branches check the write as the local one does.\n                        if (! is_string($path) || $path === '') {",
                 '                        // P1-SEC-73 (keyWiseUpload_file, s3): the remote branches check the write as the local one does.\n                        if (false) {'),
            ]),
            ('PU63 jsonUpload_file: the settings helper is mistyped again (Seting)', [
                ('app/Models/Utility.php',
                 "$name, $path, $custom_validation = [])\n    {\n        try {\n            $settings = Setting::where('created_by', 1)->pluck('value', 'name')->toArray();\n            if (!isset($settings['storage_setting'])) {\n                $settings = Utility::Setting();",
                 "$name, $path, $custom_validation = [])\n    {\n        try {\n            $settings = Setting::where('created_by', 1)->pluck('value', 'name')->toArray();\n            if (!isset($settings['storage_setting'])) {\n                $settings = Utility::Seting();"),
            ]),
            ("PU64 guest checkout: the receipt falls back to the platform's list (a WebP is refused again)", [
                ('app/Services/PaymentService.php',
                 "$dir, \\App\\Services\\PlatformUploadPolicy::imageRules(['bmp']), $receiptFile);",
                 '$dir, [], $receiptFile);'),
            ]),
            ('PU65 guest checkout: the receipt keeps the client name', [
                ('app/Services/PaymentService.php',
                 '$fileName = \\App\\Services\\PlatformUploadPolicy::receiptName($receiptFile);',
                 "$fileName = $receiptFile->getClientOriginalName() . '_' . time();"),
            ]),
            ("PU66 plan-payment slip: it falls back to the panel's list (an SVG slip is stored)", [
                ('app/Http/Controllers/BankTransferController.php',
                 "$fileName, $dir, \\App\\Services\\PlatformUploadPolicy::imageRules(['bmp']));",
                 '$fileName, $dir, []);'),
            ]),
            ("PU67 plan-payment slip: it keeps the client's own name", [
                ('app/Http/Controllers/BankTransferController.php',
                 '$fileName = \\App\\Services\\PlatformUploadPolicy::receiptName($request->payment_receipt);',
                 '$fileName = $request->payment_receipt->getClientOriginalName();'),
            ]),
            ("PU68 Store Setting screen: its seven six-argument calls fall back to the platform's list (a WebP or a GIF is refused again)", [
                ('app/Http/Controllers/AppSettingController.php',
                 "$dir, \\App\\Services\\PlatformUploadPolicy::imageRules(['svg']), $theme_image)",
                 '$dir, [], $theme_image)',
                 7),
            ]),
            ('PU69 Store Setting screen, seventh site: a refusal is a redirect where its caller reads an array (HTTP 500)', [
                ('app/Http/Controllers/AppSettingController.php',
                 '                return ["error" => true, "data" => $upload[\'msg\']];',
                 "                return redirect()->back()->with('error', $upload['msg']);"),
            ]),
            ('PU70 Store Setting screen, seventh site: a refused file stays charged to the plan', [
                ('app/Http/Controllers/AppSettingController.php',
                 '                    $owner->storage_limit = $owner->storage_limit - number_format($image_size / 1048576, 2);',
                 '                    $owner->storage_limit = $owner->storage_limit;'),
            ]),
            ("PU71 updateStoreOwnerInfo: the invoice logo keeps the client's name", [
                ('app/Http/Controllers/Api/DashboardController.php',
                 "                // P1-SEC-73 (invoice_logo): the name is built by ownerImageName(), not the client's.\n                $fileName = $this->ownerImageName($file);",
                 "                // P1-SEC-73 (invoice_logo): the name is built by ownerImageName(), not the client's.\n                $fileName = time() . '_' . basename($file->getClientOriginalName());"),
            ]),
            ("PU72 updateStoreOwnerInfo: the store image keeps the client's name", [
                ('app/Http/Controllers/Api/DashboardController.php',
                 "                // P1-SEC-73 (store_image): the name is built by ownerImageName(), not the client's.\n                $fileName = $this->ownerImageName($file);",
                 "                // P1-SEC-73 (store_image): the name is built by ownerImageName(), not the client's.\n                $fileName = time() . '_' . basename($file->getClientOriginalName());"),
            ]),
            ("PU73 updateStoreOwnerInfo: the extension is the client's, not the bytes'", [
                ('app/Http/Controllers/Api/DashboardController.php',
                 '    $extension = $file->guessExtension();',
                 '    $extension = strtolower($file->getClientOriginalExtension());'),
            ]),
            ('PU74 picker: `accept` is the configured list as it was (what the server refuses is offered)', [
                ('app/Services/UploadLimits.php',
                 '        $extensions = \\App\\Services\\PlatformUploadPolicy::allowed($rawExt, \\App\\Services\\PlatformUploadPolicy::panelSession());',
                 "        $extensions = array_values(array_filter(array_map(fn ($e) => strtolower(ltrim(trim($e), '.')), explode(',', $rawExt))));"),
            ]),
            ('PU75 picker: every request is offered the panel list', [
                ('app/Services/UploadLimits.php',
                 '\\App\\Services\\PlatformUploadPolicy::allowed($rawExt, \\App\\Services\\PlatformUploadPolicy::panelSession());',
                 '\\App\\Services\\PlatformUploadPolicy::allowed($rawExt, true);'),
            ]),
        ],
    },
    {
        "key": 'shopperattachments',
        "title": "P1-SEC-71 -- a shopper's attachment lands in its own store's folder under a name the server built, a refusal is said, and the platform uploader never lets a stranger's file replace another file",
        "tests": ['tests/Feature/Security/PlatformUploaderTypeTest.php', 'tests/Feature/Security/PlatformUploaderDoorsTest.php'],
        "mutants": [
            ("SA1 ticket create: the stored name is the client's own", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                        $imageName = \\App\\Services\\PlatformUploadPolicy::storedName($file, 'attachment');\n                        $path = Utility::keyWiseUpload_file($request,'attachments',$imageName,$dir,$filekey,[]);",
                 "                        $imageName = $file->getClientOriginalName();\n                        $path = Utility::keyWiseUpload_file($request,'attachments',$imageName,$dir,$filekey,[]);"),
            ]),
            ("SA2 ticket create AND edit (single field): the stored name is the client's own", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "            $fileName = \\App\\Services\\PlatformUploadPolicy::storedName($request->attachment, 'attachment');\n            $path = Utility::upload_file($request, 'attachment', $fileName, $dir, []);",
                 "            $fileName = $request->attachment->getClientOriginalName();\n            $path = Utility::upload_file($request, 'attachment', $fileName, $dir, []);",
                 2),
            ]),
            ("SA3 ticket edit: the stored name is the client's own", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                    $imageName = \\App\\Services\\PlatformUploadPolicy::storedName($file, 'attachment');\n                    $path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, $filekey, []);",
                 "                    $imageName = $file->getClientOriginalName();\n                    $path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, $filekey, []);"),
            ]),
            ("SA4 ticket reply: the stored name is the client's own", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                    $imageName = \\App\\Services\\PlatformUploadPolicy::storedName($file, 'attachment');\n                    $dir    = $this->shopperAttachmentDir($store, 'reply_tickets');",
                 "                    $imageName = $file->getClientOriginalName();\n                    $dir    = $this->shopperAttachmentDir($store, 'reply_tickets');"),
            ]),
            ("SA5 refund evidence: the stored name is the client's own", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                $imageName = \\App\\Services\\PlatformUploadPolicy::storedName($file, 'attachment');\n                $dir        = $this->shopperAttachmentDir($store, 'order_refund');",
                 "                $imageName = $file->getClientOriginalName();\n                $dir        = $this->shopperAttachmentDir($store, 'order_refund');"),
            ]),
            ("SA6 ticket reply: the folder is getCurrentStore()'s again (null for a shopper)", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                    $dir    = $this->shopperAttachmentDir($store, 'reply_tickets');",
                 "                    $dir    = 'uploads/' . getCurrentStore() . '/reply_tickets';"),
            ]),
            ("SA7 the shopper folder helper asks getCurrentStore() instead of the route's store", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return 'uploads/' . $store->id . '/' . $kind;",
                 "        return 'uploads/' . getCurrentStore() . '/' . $kind;"),
            ]),
            ('SA8 the shopper folder helper drops the kind of attachment', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return 'uploads/' . $store->id . '/' . $kind;",
                 "        return 'uploads/' . $store->id . '/tickets';"),
            ]),
            ('SA9 ticket create: the folder is assigned only inside the plural loop again (undefined for the single field)', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        $dir               = $this->shopperAttachmentDir($store, 'tickets');\n        $refused           = $this->rejectedByPhp($request, 'attachments');",
                 "        $refused           = $this->rejectedByPhp($request, 'attachments');"),
                ('app/Http/Controllers/AccountProfileController.php',
                 '                    if($result==1)\n                    {\n                        // P1-SEC-71: a name the SERVER builds',
                 "                    if($result==1)\n                    {\n                        $dir = $this->shopperAttachmentDir($store, 'tickets');\n                        // P1-SEC-71: a name the SERVER builds"),
            ]),
            ('SA10 ticket create: a refused attachment is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 '                            // P1-SEC-71: counted and SAID. The answer was assigned to a variable nobody read, so a dropped file looked like a saved one.\n                            $refused++;',
                 "                            // P1-SEC-71: counted and SAID. The answer was assigned to a variable nobody read, so a dropped file looked like a saved one.\n                            $errors = __($path['msg']);"),
            ]),
            ('SA11 ticket create (single field): a refused attachment is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "            if ($path['flag'] == '0') {\n                $refused++;\n            } else {\n                $tickets->attachment    = $path['url'];",
                 "            if ($path['flag'] == '0') {\n                $errors = $path['msg'];\n            } else {\n                $tickets->attachment    = $path['url'];"),
            ]),
            ('SA12 ticket edit: a refused attachment is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                        $data[] = $path['url'];\n                    } else {\n                        $refused++;\n                    }\n                } else {\n                    $refused++;        // the merchant's plan is full",
                 "                        $data[] = $path['url'];\n                    } else {\n                        $errors = __($path['msg']);\n                    }\n                } else {\n                    $refused++;        // the merchant's plan is full"),
            ]),
            ('SA13 ticket edit (single field): a refused attachment is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "            if ($path['flag'] == '0') {\n                $refused++;\n            } else {\n                $ticket->attachment      = $path['url'];",
                 "            if ($path['flag'] == '0') {\n                $errors = $path['msg'];\n            } else {\n                $ticket->attachment      = $path['url'];"),
            ]),
            ('SA14 ticket reply: a refused attachment is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                        $data[] = $path['url'];\n                    } else {\n                        $refused++;\n                    }\n                }\n            }\n            $post['attachments']",
                 "                        $data[] = $path['url'];\n                    } else {\n                        $errors = __($path['msg']);\n                    }\n                }\n            }\n            $post['attachments']"),
            ]),
            ('SA15 refund evidence: a refused attachment is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                    $data[] = $path['url'];\n                } else {\n                    $refused++;\n                }\n                $order_refund->attachments",
                 "                    $data[] = $path['url'];\n                } else {\n                    $errors = __($path['msg']);\n                }\n                $order_refund->attachments"),
            ]),
            ('SA16 the refusal helper never says anything', [
                ('app/Http/Controllers/AccountProfileController.php',
                 '        if ($refused === 0) {\n            return $response;\n        }',
                 '        if (true) {\n            return $response;\n        }'),
            ]),
            ('SA17 the refusal helper says it as a success', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return $response->with('error', $mayTryAgain",
                 "        return $response->with('success', $mayTryAgain"),
            ]),
            ('SA18 the refusal helper says something else', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "            ? __('An attachment was not accepted. Check its type and size and try again.')\n",
                 "            ? __('Something went wrong.')\n"),
            ]),
            ('SA19 storedName: no random part', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return ($base !== '' ? $base : $fallback) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($file->getClientOriginalExtension());",
                 "        return ($base !== '' ? $base : $fallback) . '_' . time() . '.' . strtolower($file->getClientOriginalExtension());"),
            ]),
            ('SA20 storedName: the base is not truncated', [
                ('app/Services/PlatformUploadPolicy.php',
                 "'_'), 0, 60), '_');",
                 "'_'), 0, 600), '_');"),
            ]),
            ("SA21 storedName: a dot of the client's survives into the name", [
                ('app/Services/PlatformUploadPolicy.php',
                 "preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '_'), 0, 60), '_');",
                 "preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '_'), 0, 60), '_');"),
            ]),
            ("SA22 storedName: the fallback word is fixed, not the caller's", [
                ('app/Services/PlatformUploadPolicy.php',
                 "return ($base !== '' ? $base : $fallback) . '_' . time() . '_' . bin2hex(random_bytes(4))",
                 "return ($base !== '' ? $base : 'file') . '_' . time() . '_' . bin2hex(random_bytes(4))"),
            ]),
            ('SA23 storedName: the extension is not lower-cased', [
                ('app/Services/PlatformUploadPolicy.php',
                 "'.' . strtolower($file->getClientOriginalExtension());\n    }\n\n    /**\n     * A SHOPPER'S UPLOAD NEVER REPLACES",
                 "'.' . $file->getClientOriginalExtension();\n    }\n\n    /**\n     * A SHOPPER'S UPLOAD NEVER REPLACES"),
            ]),
            ('SA24 receiptName: no longer the same shape (the fallback word is lost)', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return self::storedName($file, 'receipt');",
                 '        return self::storedName($file);'),
            ]),
            ('SA25 nonReplacingName: nobody is a shopper request', [
                ('app/Services/PlatformUploadPolicy.php',
                 '        if (! self::shopperRequest()) {\n            return $name;\n        }',
                 '        if (true) {\n            return $name;\n        }'),
            ]),
            ('SA26 nonReplacingName: the existence check is skipped', [
                ('app/Services/PlatformUploadPolicy.php',
                 "            $taken = $storage->exists($folder . '/' . $name);",
                 '            $taken = false;'),
            ]),
            ('SA27 nonReplacingName: the new name loses its extension', [
                ('app/Services/PlatformUploadPolicy.php',
                 "($extension !== '' ? '.' . $extension : '');",
                 "'';"),
            ]),
            ('SA28 nonReplacingName: the new name has no random part', [
                ('app/Services/PlatformUploadPolicy.php',
                 "return $base . '_' . time() . '_' . bin2hex(random_bytes(8))",
                 "return $base . '_' . time()"),
            ]),
            ('SA29 nonReplacingName: the check is made on the wrong disk', [
                ('app/Services/PlatformUploadPolicy.php',
                 '        $storage = Storage::disk($disk);',
                 "        $storage = Storage::disk('theme');"),
            ]),
            ("SA30 shopperRequest: a call with no router is a stranger's request too", [
                ('app/Services/PlatformUploadPolicy.php',
                 '        return request()->route() instanceof Route && ! self::panelSession();',
                 '        return ! self::panelSession();'),
            ]),
            ("SA31 shopperRequest: a panel request is a stranger's request too", [
                ('app/Services/PlatformUploadPolicy.php',
                 '        return request()->route() instanceof Route && ! self::panelSession();',
                 '        return request()->route() instanceof Route;'),
            ]),
            ('SA32 upload_file: the call is gone (a stranger replaces)', [
                ('app/Models/Utility.php',
                 "                    $name = \\App\\Services\\PlatformUploadPolicy::nonReplacingName($settings['storage_setting'] == 'local' ? 'theme' : (string) $settings['storage_setting'], (string) $path, $name);\n\n                    if ($settings['storage_setting'] == 'local') {\n                        $path = Str::finish($path, '/');\n                        createAndSetPermissionsRecursively($path, 'theme');\n                       \n                        $image = !empty($image) ? $image : (is_array($request) ? $request[$key_name] : $request->file($key_name));\n                        $written = \\Storage::disk('theme')->putFileAs(\n                            $path,\n                            $image,\n                            $name\n                        );\n                        // P1-SEC-73 (upload_file):",
                 "                    $name = $name;\n\n                    if ($settings['storage_setting'] == 'local') {\n                        $path = Str::finish($path, '/');\n                        createAndSetPermissionsRecursively($path, 'theme');\n                       \n                        $image = !empty($image) ? $image : (is_array($request) ? $request[$key_name] : $request->file($key_name));\n                        $written = \\Storage::disk('theme')->putFileAs(\n                            $path,\n                            $image,\n                            $name\n                        );\n                        // P1-SEC-73 (upload_file):"),
            ]),
            ('SA33 keyWiseUpload_file: the call is gone (a stranger replaces)', [
                ('app/Models/Utility.php',
                 "                    $name = \\App\\Services\\PlatformUploadPolicy::nonReplacingName($settings['storage_setting'] == 'local' ? 'theme' : (string) $settings['storage_setting'], $settings['storage_setting'] == 'local' ? 'uploads/' . $path : (string) $path, $name);",
                 '                    $name = $name;'),
            ]),
            ('SA34 jsonUpload_file: the call is gone (a stranger replaces)', [
                ('app/Models/Utility.php',
                 "                    // P1-SEC-71 (jsonUpload_file): a stranger's upload never replaces a file that is already there -- see PlatformUploadPolicy::nonReplacingName().\n                    $name = \\App\\Services\\PlatformUploadPolicy::nonReplacingName($settings['storage_setting'] == 'local' ? 'theme' : (string) $settings['storage_setting'], (string) $path, $name);\n",
                 "                    // P1-SEC-71 (jsonUpload_file): a stranger's upload never replaces a file that is already there -- see PlatformUploadPolicy::nonReplacingName().\n"),
            ]),
            ('SA35 multi_upload_file: the call is gone (a stranger replaces)', [
                ('app/Helper/helper.php',
                 "                    $name = \\App\\Services\\PlatformUploadPolicy::nonReplacingName((string) $storage_settings['storage_setting'], (string) $path, $name);\n",
                 '                    $name = $name;\n'),
            ]),
            ('SA36 keyWiseUpload_file: the folder it checks is not the folder it writes to (the `uploads/` prefix is missing)', [
                ('app/Models/Utility.php',
                 "$settings['storage_setting'] == 'local' ? 'uploads/' . $path : (string) $path, $name);",
                 '(string) $path, $name);'),
            ]),
            ('SA37 upload_file: the local branch is checked on the wrong disk', [
                ('app/Models/Utility.php',
                 "                    // P1-SEC-71 (upload_file): a stranger's upload never replaces a file that is already there -- see PlatformUploadPolicy::nonReplacingName().\n                    $name = \\App\\Services\\PlatformUploadPolicy::nonReplacingName($settings['storage_setting'] == 'local' ? 'theme' : (string) $settings['storage_setting']",
                 "                    // P1-SEC-71 (upload_file): a stranger's upload never replaces a file that is already there -- see PlatformUploadPolicy::nonReplacingName().\n                    $name = \\App\\Services\\PlatformUploadPolicy::nonReplacingName($settings['storage_setting'] == 'local' ? 'local' : (string) $settings['storage_setting']"),
            ]),
            ('SA38 multi_upload_file: the check is made on the wrong disk', [
                ('app/Helper/helper.php',
                 "nonReplacingName((string) $storage_settings['storage_setting'], (string) $path, $name);",
                 "nonReplacingName('theme', (string) $path, $name);"),
            ]),
            ('SA39 ticket create: the folder is getCurrentStore() ?? 1', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        $dir               = $this->shopperAttachmentDir($store, 'tickets');\n        $refused           = $this->rejectedByPhp($request, 'attachments');",
                 "        $dir               = 'uploads/' . (getCurrentStore() ?? 1) . '/tickets';\n        $refused           = $this->rejectedByPhp($request, 'attachments');"),
            ]),
            ('SA40 ticket edit: the folder is getCurrentStore() ?? 1', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        $dir     = $this->shopperAttachmentDir($store, 'tickets');",
                 "        $dir     = 'uploads/' . (getCurrentStore() ?? 1) . '/tickets';"),
            ]),
            ('SA41 refund evidence: the folder is getCurrentStore() ?? 1', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                $dir        = $this->shopperAttachmentDir($store, 'order_refund');",
                 "                $dir        = 'uploads/' . (getCurrentStore() ?? 1) . '/order_refund';"),
            ]),
            ('SA42 ticket reply: the folder is getCurrentStore() ?? 1', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                    $dir    = $this->shopperAttachmentDir($store, 'reply_tickets');",
                 "                    $dir    = 'uploads/' . (getCurrentStore() ?? 1) . '/reply_tickets';"),
            ]),
            ("SA43 the shopper folder helper uses the store OWNER's user id", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return 'uploads/' . $store->id . '/' . $kind;",
                 "        return 'uploads/' . $store->created_by . '/' . $kind;"),
            ]),
            ("SA44 the shopper folder helper asks getCurrentStore() and falls back to the route's store (the natural 'safe' regression)", [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return 'uploads/' . $store->id . '/' . $kind;",
                 "        return 'uploads/' . (getCurrentStore() ?? $store->id) . '/' . $kind;"),
            ]),
            ('SA45 ticket create: an accepted attachment is counted as refused', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "__('Ticket successfully created.')), $refused, ! $planFull);",
                 "__('Ticket successfully created.')), $refused + 1, ! $planFull);"),
            ]),
            ('SA46 ticket edit: an accepted attachment is counted as refused', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "__('Ticket successfully updated.')), $refused, ! $planFull);",
                 "__('Ticket successfully updated.')), $refused + 1, ! $planFull);"),
            ]),
            ('SA47 refund evidence: an accepted attachment is counted as refused', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "__('Refund Request Send successfully!')), $refused, false);",
                 "__('Refund Request Send successfully!')), $refused + 1, false);"),
            ]),
            ('SA48 ticket create (single field): the accepted branch is never taken', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "            if ($path['flag'] == '0') {\n                $refused++;\n            } else {\n                $tickets->attachment    = $path['url'];",
                 "            if (true) {\n                $refused++;\n            } else {\n                $tickets->attachment    = $path['url'];"),
            ]),
            ('SA49 ticket edit (single field): the accepted branch is never taken', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "            if ($path['flag'] == '0') {\n                $refused++;\n            } else {\n                $ticket->attachment      = $path['url'];",
                 "            if (true) {\n                $refused++;\n            } else {\n                $ticket->attachment      = $path['url'];"),
            ]),
            ('SA50 ticket reply: a later accepted file forgets an earlier refusal', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                        $data[] = $path['url'];\n                    } else {\n                        $refused++;\n                    }\n                }\n            }\n            $post['attachments']",
                 "                        $data[] = $path['url'];\n                        $refused = 0;\n                    } else {\n                        $refused++;\n                    }\n                }\n            }\n            $post['attachments']"),
            ]),
            ('SA51 refund evidence: a later accepted file forgets an earlier refusal', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                    $data[] = $path['url'];\n                } else {\n                    $refused++;\n                }\n                $order_refund->attachments",
                 "                    $data[] = $path['url'];\n                    $refused = 0;\n                } else {\n                    $refused++;\n                }\n                $order_refund->attachments"),
            ]),
            ('SA52 ticket edit: a later accepted file forgets an earlier refusal', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                        $data[] = $path['url'];\n                    } else {\n                        $refused++;\n                    }\n                } else {\n                    $refused++;        // the merchant's plan is full",
                 "                        $data[] = $path['url'];\n                        $refused = 0;\n                    } else {\n                        $refused++;\n                    }\n                } else {\n                    $refused++;        // the merchant's plan is full"),
            ]),
            ('SA53 ticket create: a later accepted file forgets an earlier refusal', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                        if($path['flag'] == 1){\n                            $data[] = $path['url'];\n",
                 "                        if($path['flag'] == 1){\n                            $data[] = $path['url'];\n                            $refused = 0;\n"),
            ]),
            ('SA54 refund evidence: the first refused file ends the loop', [
                ('app/Http/Controllers/AccountProfileController.php',
                 '                    $refused++;\n                }\n                $order_refund->attachments',
                 '                    $refused++;\n                    break;\n                }\n                $order_refund->attachments'),
            ]),
            ('SA55 ticket reply: every file is stored from index 0', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "Utility::keyWiseUpload_file($request, 'reply_attachments', $imageName, $dir, $filekey, []);",
                 "Utility::keyWiseUpload_file($request, 'reply_attachments', $imageName, $dir, 0, []);"),
            ]),
            ('SA56 refund evidence: every file is stored from index 0', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "$path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, $filekey, []);\n\n                if ($path['flag'] == 1) {",
                 "$path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, 0, []);\n\n                if ($path['flag'] == 1) {"),
            ]),
            ('SA57 ticket edit: every file is stored from index 0', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "$path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, $filekey, []);\n                    if ($path['flag'] == 1) {",
                 "$path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, 0, []);\n                    if ($path['flag'] == 1) {"),
            ]),
            ('SA58 ticket create: every file is stored from index 0', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "$path = Utility::keyWiseUpload_file($request,'attachments',$imageName,$dir,$filekey,[]);",
                 "$path = Utility::keyWiseUpload_file($request,'attachments',$imageName,$dir,0,[]);"),
            ]),
            ('SA59 ticket create: the single field starts a fresh count (a refused plural file is forgotten)', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        if ($request->attachment) {\n            $fileName = \\App\\Services\\PlatformUploadPolicy::storedName($request->attachment, 'attachment');\n            $path = Utility::upload_file($request, 'attachment', $fileName, $dir, []);\n            if ($path['flag'] == '0') {\n                $refused++;\n            } else {\n                $tickets->attachment",
                 "        if ($request->attachment) {\n            $refused = 0;\n            $fileName = \\App\\Services\\PlatformUploadPolicy::storedName($request->attachment, 'attachment');\n            $path = Utility::upload_file($request, 'attachment', $fileName, $dir, []);\n            if ($path['flag'] == '0') {\n                $refused++;\n            } else {\n                $tickets->attachment"),
            ]),
            ('SA60 ticket edit: the plural loop starts a fresh count (a refused single field is forgotten)', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        if ($request->hasfile('attachments')) {\n\n            foreach ($request->file('attachments') as $filekey => $file) {\n                $file_size = $file->getSize();",
                 "        if ($request->hasfile('attachments')) {\n            $refused = 0;\n\n            foreach ($request->file('attachments') as $filekey => $file) {\n                $file_size = $file->getSize();"),
            ]),
            ('SA61 refund evidence: the evidence is never linked to its refund row', [
                ('app/Http/Controllers/AccountProfileController.php',
                 '$order_refund->attachments    =  json_encode($data);',
                 '$order_refund->attachments    =  null;'),
            ]),
            ('SA62 ticket create (single field): the ticket never points at the file', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "                $tickets->attachment    = $path['url'];",
                 '                $tickets->attachment    = null;'),
            ]),
            ('SA63 receiptName: the pre-change body again (no sixty-character cap)', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        return self::storedName($file, 'receipt');",
                 "        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '_');\n\n        return ($base !== '' ? $base : 'receipt') . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($file->getClientOriginalExtension());"),
            ]),
            ('SA64 multi_upload_file: a remote backend is checked on the local disk', [
                ('app/Helper/helper.php',
                 "nonReplacingName((string) $storage_settings['storage_setting'], (string) $path, $name);",
                 "nonReplacingName('local', (string) $path, $name);"),
            ]),
            ('SA65 policy (BYP-3): one invalid UTF-8 byte disables the format-character check again', [
                ('app/Services/PlatformUploadPolicy.php',
                 "preg_match('/\\p{C}/u', $name) !== 0",
                 "preg_match('/\\p{C}/u', $name) === 1"),
            ]),
            ('SA66 nonReplacingName (CR-02): a disk that cannot answer exists() refuses the upload again', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            $taken = true;\n        }',
                 '            throw $e;\n        }'),
            ]),
            ('SA67 nonReplacingName (CR-02): a disk that cannot answer exists() is treated as FREE', [
                ('app/Services/PlatformUploadPolicy.php',
                 '            $taken = true;\n        }',
                 '            $taken = false;\n        }'),
            ]),
            ('SA68 ticket create (BYP-1): a file PHP itself rejected is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        $refused           = $this->rejectedByPhp($request, 'attachments');\n        $planFull          = false;",
                 '        $refused           = 0;\n        $planFull          = false;'),
            ]),
            ('SA69 ticket edit (BYP-1): a file PHP itself rejected is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        $refused = $this->rejectedByPhp($request, 'attachments');\n        $planFull = false;",
                 '        $refused = 0;\n        $planFull = false;'),
            ]),
            ('SA70 ticket reply (BYP-1): a file PHP itself rejected is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "$refused             = $this->rejectedByPhp($request, 'reply_attachments');",
                 '$refused             = 0;'),
            ]),
            ('SA71 refund evidence (BYP-1): a file PHP itself rejected is not counted', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        $refused           = $this->rejectedByPhp($request, 'attachments');\n        if ($request->hasfile('attachments')) {",
                 "        $refused           = 0;\n        if ($request->hasfile('attachments')) {"),
            ]),
            # SA72 and SA73 are withdrawn. They mutated a skip of a file PHP itself rejected inside the create and edit loops, added on the premise
            # that `getSize()` raises for it. It answers false (executed, PHP 8.3.6), the uploader refuses the file anyway, and no test could tell the
            # skip from its absence (both mutants survived the full group), so the skip was removed instead of declared an equivalent survivor.
            ('SA74 refund evidence (CR-05): the sentence says "try again" for a refund that cannot be sent again', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "__('Refund Request Send successfully!')), $refused, false);",
                 "__('Refund Request Send successfully!')), $refused);"),
            ]),
            ('SA75 ticket create (R71-TEN-02): a full plan is told as "try again"', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "__('Ticket successfully created.')), $refused, ! $planFull);",
                 "__('Ticket successfully created.')), $refused, true);"),
            ]),
            ('SA76 ticket edit (R71-TEN-02): a full plan is told as "try again"', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "__('Ticket successfully updated.')), $refused, ! $planFull);",
                 "__('Ticket successfully updated.')), $refused, true);"),
            ]),
            ('SA77 storedName (CR-07): the cut can leave a trailing underscore', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        $base = rtrim(substr(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '_'), 0, 60), '_');",
                 "        $base = substr(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), '_'), 0, 60);"),
            ]),
            ('SA78 routeDemandsAPanelUser (CR-06): the module manifest is not consulted', [
                ('app/Services/PlatformUploadPolicy.php',
                 '        return \\App\\Support\\ModuleRouteManifest::administrativeRule(request()->method(), $route->uri()) !== null;',
                 '        return false;'),
            ]),
            ('SA79 nonReplacingName (R71-TEN-04): a rename leaves no trace', [
                ('app/Services/PlatformUploadPolicy.php',
                 "        Log::info('a stranger\\'s upload was stored under a new name because its name was taken', ['disk' => $disk, 'folder' => $folder]);\n",
                 ''),
            ]),
            ('SA80 nonReplacingName (R71-TEN-04): the log line carries the file name', [
                ('app/Services/PlatformUploadPolicy.php',
                 "['disk' => $disk, 'folder' => $folder]);",
                 "['disk' => $disk, 'folder' => $folder . '/' . $name]);"),
            ]),
            ('SA81 the refusal helper never says "try again"', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return $response->with('error', $mayTryAgain\n            ?",
                 "        return $response->with('error', false\n            ?"),
            ]),
            ('SA82 the refusal helper always says "try again"', [
                ('app/Http/Controllers/AccountProfileController.php',
                 "        return $response->with('error', $mayTryAgain\n            ?",
                 "        return $response->with('error', true\n            ?"),
            ]),
        ],
    },
    {
        "key": 'signinimagedelete',
        "title": "P1-SEC-70 -- a vendored sign-in settings handler deletes a file only if the row it chose is the signed-in user's store's AND the file, as the operating system resolves it, is in a place that store may delete from; the guard is bound to a POST to those two handlers and nothing else",
        "tests": ['tests/Feature/Security/SignInImageDeleteGuardTest.php'],
        "mutants": [
            ('SD1 the guard never binds the File facade', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 '        File::swap(new StoreBoundFilesystem($store, $mine));\n',
                 ''),
            ]),
            ('SD2 the original File facade is not put back', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 '            File::swap($original);\n',
                 ''),
            ]),
            ('SD3 a GET to a guarded route is bound too', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 "$name = $route === null || ! $request->isMethod('POST') ? null : $this->rowNamed((string) $route->getActionName());",
                 '$name = $route === null ? null : $this->rowNamed((string) $route->getActionName());'),
            ]),
            ('SD4 the LinkedIn handler is not guarded', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 "        'SignInWithLinkedInController@LinkedinSettings' => 'linkedin_sign_in_image',\n",
                 ''),
            ]),
            ('SD5 the Facebook handler is not guarded', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 "        'SignInWithFacebookController@facebookSettings' => 'facebook_sign_in_image',\n",
                 ''),
            ]),
            ('SD6 every route is bound (the action is not compared)', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 'if (str_ends_with($action, $suffix)) {',
                 'if (true) {'),
            ]),
            ('SD7 the handler is recognised by its whole action, which the namespace makes impossible', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 'if (str_ends_with($action, $suffix)) {',
                 'if ($action === $suffix) {'),
            ]),
            ('SD8 the filesystem is bound to store 1 whoever is signed in', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 '$store = (int) getCurrentStore();',
                 '$store = 1;'),
            ]),
            ('SD9 the row is never asked whose it is', [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 '$mine = $row === null || StoredPathPolicy::sameTenantStores($row->store_id, $store);',
                 '$mine = true;'),
            ]),
            ("SD10 a row is the store's only when it names that very store (the owner's other stores are strangers)", [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 '$mine = $row === null || StoredPathPolicy::sameTenantStores($row->store_id, $store);',
                 '$mine = $row === null || (int) $row->store_id === $store;'),
            ]),
            ("SD11 a row with no store is the store's", [
                ('app/Http/Middleware/SignInImageDeleteGuard.php',
                 'StoredPathPolicy::sameTenantStores($row->store_id, $store);',
                 '($row->store_id === null || StoredPathPolicy::sameTenantStores($row->store_id, $store));'),
            ]),
            ('SD12 the filesystem does not consult the row', [
                ('app/Support/StoreBoundFilesystem.php',
                 "            if (! $this->rowIsTheStores) {\n                return 'row';\n            }\n\n",
                 ''),
            ]),
            ('SD13 an upload is not judged by the store rule', [
                ('app/Support/StoreBoundFilesystem.php',
                 "return StoredPathPolicy::isSafe('uploads/' . ltrim(substr($real, strlen($resolved)), '/'), $this->storeId) ? null : 'path';",
                 'return null;'),
            ]),
            ('SD14 a path outside the upload roots and the framework areas is allowed', [
                ('app/Support/StoreBoundFilesystem.php',
                 "return $this->inFrameworkArea($real) ? null : 'outside';",
                 'return null;'),
            ]),
            ('SD15 the directory is not resolved by the operating system', [
                ('app/Support/StoreBoundFilesystem.php',
                 '$directory = realpath(dirname($path));',
                 '$directory = dirname($path);'),
            ]),
            ('SD16 a link is not a thing a delete can remove', [
                ('app/Support/StoreBoundFilesystem.php',
                 'return is_file($real) || is_link($real) ? $real : null;',
                 'return is_file($real) ? $real : null;'),
            ]),
            ('SD17 a sibling folder that begins with `uploads` is under the root', [
                ('app/Support/StoreBoundFilesystem.php',
                 "$resolved === false || ! str_starts_with($real . '/', $resolved . '/')",
                 '$resolved === false || ! str_starts_with($real, $resolved)'),
            ]),
            ('SD18 only the first upload root is a root', [
                ('app/Support/StoreBoundFilesystem.php',
                 "foreach (['uploads', 'public/uploads', 'storage/uploads'] as $root) {",
                 "foreach (['uploads'] as $root) {"),
            ]),
            ('SD19 only `storage/framework` is a framework area', [
                ('app/Support/StoreBoundFilesystem.php',
                 "foreach (['storage/framework', 'bootstrap/cache'] as $area) {",
                 "foreach (['storage/framework'] as $area) {"),
            ]),
            ('SD20 an unknown store is not refused as such', [
                ('app/Support/StoreBoundFilesystem.php',
                 "            if ($this->storeId <= 0) {\n                return 'no store';\n            }\n\n",
                 ''),
            ]),
            ('SD21 deleteDirectory() is not bound', [
                ('app/Support/StoreBoundFilesystem.php',
                 'return $this->mayTouchDirectory((string) $directory) ? parent::deleteDirectory($directory, $preserve) : false;',
                 'return parent::deleteDirectory($directory, $preserve);'),
            ]),
            ('SD22 a refused directory call leaves no trace', [
                ('app/Support/StoreBoundFilesystem.php',
                 "            $this->refused('directory');\n",
                 ''),
            ]),
            ('SD23 any existing directory may be removed', [
                ('app/Support/StoreBoundFilesystem.php',
                 "if ($real !== false && $this->inFrameworkArea($real . '/x')) {",
                 'if ($real !== false) {'),
            ]),
            ('SD24 a refusal names no store', [
                ('app/Support/StoreBoundFilesystem.php',
                 "['store' => $this->storeId, 'reason' => $reason]",
                 "['reason' => $reason]"),
            ]),
            ('SD25 a refusal gives no reason', [
                ('app/Support/StoreBoundFilesystem.php',
                 "['store' => $this->storeId, 'reason' => $reason]",
                 "['store' => $this->storeId]"),
            ]),
            ('SD26 a refusal leaves no trace', [
                ('app/Support/StoreBoundFilesystem.php',
                 "        Log::warning('a delete outside the current store was refused', ['store' => $this->storeId, 'reason' => $reason]);\n",
                 ''),
            ]),
            ('SD27 a failed allowed delete still answers true', [
                ('app/Support/StoreBoundFilesystem.php',
                 '$success = parent::delete($real) && $success;',
                 'parent::delete($real);'),
            ]),
            ('SD28 the variadic form looks at its first path only', [
                ('app/Support/StoreBoundFilesystem.php',
                 '$paths = is_array($paths) ? $paths : func_get_args();',
                 '$paths = is_array($paths) ? $paths : [$paths];'),
            ]),
            ('SD29 a refused path is deleted anyway', [
                ('app/Support/StoreBoundFilesystem.php',
                 '            if ($reason !== null) {\n                $this->refused($reason);\n                $success = false;\n\n                continue;\n            }\n\n',
                 ''),
            ]),
            ('SD30 store 0 owns the folder called 0', [
                ('app/Services/StoredPathPolicy.php',
                 'if ($a === null || $b === null || (int) $a <= 0 || (int) $b <= 0) {',
                 'if ($a === null || $b === null || (int) $a < 0 || (int) $b < 0) {'),
            ]),
            ('SD31 a store its owner also runs is a stranger', [
                ('app/Services/StoredPathPolicy.php',
                 "        return $owner !== null && (string) \\App\\Models\\Store::query()->whereKey((int) $a)->value('created_by') === (string) $owner;\n",
                 '        return false;\n'),
            ]),
            ("SD33 a folder spelled with leading zeros is the store's folder", [
                ('app/Services/StoredPathPolicy.php',
                 ' || $folder !== (string) (int) $folder',
                 ''),
            ]),
            ('SD32 the guard is not registered on the web group', [
                ('bootstrap/app.php',
                 "        $middleware->appendToGroup('web', \\App\\Http\\Middleware\\SignInImageDeleteGuard::class);\n",
                 ''),
            ]),
        ],
    },
    {
        "key": 'helpdesk',
        "title": "P1-SEC-68 -- the seven authenticated Helpdesk ticket routes are judged by who owns the ticket (and the user lookup is the operator's alone), by ModuleAdministrativeRouteGuard rules and their manifest entries",
        "tests": ['tests/Feature/Security/HelpdeskTicketOwnershipTest.php'],
        "mutants": [
            ('HD1 the row rule always allows', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "'owner:helpdesk-ticket-row' => $this->ownsTicketRow($user, $route->parameter('ticketId')),",
                 "'owner:helpdesk-ticket-row' => true,"),
            ]),
            ('HD3 the row rule reads the BODY before the route', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "$this->ownsTicketRow($user, $route->parameter('ticketId'))",
                 "$this->ownsTicketRow($user, $request->input('ticketId', $route->parameter('ticketId')))"),
            ]),
            ('HD5 the row rule looks the ticket up by NUMBER', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "$this->ticketIsTheTenants($user, 'id', $rowId)",
                 "$this->ticketIsTheTenants($user, 'ticket_id', $rowId)"),
            ]),
            ('HD6 created_by AND user_id', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '($this->sameTenant($user, $ticket->created_by) || $this->sameTenant($user, $ticket->user_id))',
                 '($this->sameTenant($user, $ticket->created_by) && $this->sameTenant($user, $ticket->user_id))'),
            ]),
            ('HD7 the created_by limb is dropped', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '($this->sameTenant($user, $ticket->created_by) || $this->sameTenant($user, $ticket->user_id))',
                 '($this->sameTenant($user, $ticket->user_id))'),
            ]),
            ('HD8 the user_id limb is dropped', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '($this->sameTenant($user, $ticket->created_by) || $this->sameTenant($user, $ticket->user_id))',
                 '($this->sameTenant($user, $ticket->created_by))'),
            ]),
            ('HD9 a missing ticket is not refused first', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 'return $ticket !== null\n            && ($this->sameTenant',
                 'return ($this->sameTenant'),
            ]),
            ('HD16 the operator is not admitted first', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "if ($rule === 'auth' || $this->isOperator($user)) {",
                 "if ($rule === 'auth') {"),
            ]),
            ('HD17 the edit screen is judged as a plain number', [
                ('app/Support/ModuleRouteManifest.php',
                 "'GET edit-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',",
                 "'GET edit-ticket/{ticketId}' => 'owner:helpdesk-ticket',"),
            ]),
            ('HD18 the note route has no rule', [
                ('app/Support/ModuleRouteManifest.php',
                 "        'POST ticket-note-store/{ticketId}' => 'owner:helpdesk-ticket-row',\n",
                 ''),
            ]),
            ('HD19 delete is signed-in only', [
                ('app/Support/ModuleRouteManifest.php',
                 "'DELETE delete-ticket/{ticketId}' => 'owner:helpdesk-ticket-row',",
                 "'DELETE delete-ticket/{ticketId}' => 'auth',"),
            ]),
            ('HD20 update is signed-in only', [
                ('app/Support/ModuleRouteManifest.php',
                 "'POST update-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',",
                 "'POST update-ticket/{ticketId}' => 'auth',"),
            ]),
            ('HD21 the conversation is signed-in only', [
                ('app/Support/ModuleRouteManifest.php',
                 "'GET ticket-reply/{ticketId}' => 'owner:helpdesk-ticket-link',",
                 "'GET ticket-reply/{ticketId}' => 'auth',"),
            ]),
            ('HD22 the user lookup is signed-in only', [
                ('app/Support/ModuleRouteManifest.php',
                 "'POST get-user-data' => 'operator',",
                 "'POST get-user-data' => 'auth',"),
            ]),
            ('HD23 reply is signed-in only', [
                ('app/Support/ModuleRouteManifest.php',
                 "'POST ticket-conversion-store/{ticketId}' => 'owner:helpdesk-ticket-row',",
                 "'POST ticket-conversion-store/{ticketId}' => 'auth',"),
            ]),
            ('HD24 the edit screen is signed-in only', [
                ('app/Support/ModuleRouteManifest.php',
                 "'GET edit-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',",
                 "'GET edit-ticket/{ticketId}' => 'auth',"),
            ]),
            ('HD25 delete takes the encrypted-number rule', [
                ('app/Support/ModuleRouteManifest.php',
                 "'DELETE delete-ticket/{ticketId}' => 'owner:helpdesk-ticket-row',",
                 "'DELETE delete-ticket/{ticketId}' => 'owner:helpdesk-ticket-link',"),
            ]),
            ('HD26 note takes the raw-number rule', [
                ('app/Support/ModuleRouteManifest.php',
                 "'POST ticket-note-store/{ticketId}' => 'owner:helpdesk-ticket-row',",
                 "'POST ticket-note-store/{ticketId}' => 'owner:helpdesk-ticket',"),
            ]),
            ('HD27 the tenant is creatorId() equality, with no merchant-root test', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '($this->sameTenant($user, $ticket->created_by) || $this->sameTenant($user, $ticket->user_id))',
                 '((int) User::find($ticket->created_by)?->creatorId() === (int) $user->creatorId() || (int) User::find($ticket->user_id)?->creatorId() === (int) $user->creatorId())'),
            ]),
            ('HD28 the row rule lets every DELETE through', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "'owner:helpdesk-ticket-row' => $this->ownsTicketRow($user, $route->parameter('ticketId')),",
                 "'owner:helpdesk-ticket-row' => $this->ownsTicketRow($user, $route->parameter('ticketId')) || $request->isMethod('DELETE'),"),
            ]),
            ('HD30 the encrypted number is judged as a ROW id', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "$this->ticketIsTheTenants($user, 'ticket_id', (string) $ticketNumber)",
                 "$this->ticketIsTheTenants($user, 'id', (string) $ticketNumber)"),
            ]),
            ('HD31 the decoder is not anchored at the start', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '\'/\\A(?:i:(\\d{1,18});|s:(\\d{1,2}):"(\\d{1,18})";)\\z/\'',
                 '\'/(?:i:(\\d{1,18});|s:(\\d{1,2}):"(\\d{1,18})";)\\z/\''),
            ]),
            ('HD32 the decoder is not anchored at the end', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '\'/\\A(?:i:(\\d{1,18});|s:(\\d{1,2}):"(\\d{1,18})";)\\z/\'',
                 '\'/\\A(?:i:(\\d{1,18});|s:(\\d{1,2}):"(\\d{1,18})";)/\''),
            ]),
            ('HD33 the declared length of an s:L: is not checked', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 'return (int) $m[2] === strlen($m[3]) ? $m[3] : null;',
                 'return $m[3];'),
            ]),
            ('HD34 the operator is admitted before the segment is read', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "        if ($rule === 'owner:helpdesk-ticket-link' && $linked === null) {\n            return $this->deny($request, false);\n        }\n\n",
                 ''),
            ]),
            ('HD35 the segment is judged as it is written, not as it decodes', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "$linked = $rule === 'owner:helpdesk-ticket-link' ? $this->linkedTicketNumber($route->parameter('ticketId')) : null;",
                 "$linked = $rule === 'owner:helpdesk-ticket-link' ? $route->parameter('ticketId') : null;"),
            ]),
            ('HD36 a row id is cast, so a tail is tolerated', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 "$rowId = $this->usableId($rowId);\n\n        return $rowId !== null && $this->ticketIsTheTenants($user, 'id', $rowId);",
                 "$rowId = (int) $rowId;\n\n        return $rowId !== null && $this->ticketIsTheTenants($user, 'id', $rowId);"),
            ]),
            ('HD37 the eighteen-digit cap is removed', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 '\'/\\A(?:i:(\\d{1,18});|s:(\\d{1,2}):"(\\d{1,18})";)\\z/\'',
                 '\'/\\A(?:i:(\\d+);|s:(\\d{1,2}):"(\\d+)";)\\z/\''),
            ]),
            ('HD38 a declared length LONGER than the number is accepted', [
                ('app/Http/Middleware/ModuleAdministrativeRouteGuard.php',
                 'return (int) $m[2] === strlen($m[3]) ? $m[3] : null;',
                 'return (int) $m[2] >= strlen($m[3]) ? $m[3] : null;'),
            ]),
        ],
    },
    {
        "key": 'objectfree',
        "title": "P1-SEC-77 -- decrypt() hands PHP nothing but plain data: ObjectFreeEncrypter, its grammar, and its registration over the framework singleton",
        "tests": ['tests/Feature/Security/ObjectFreeEncrypterTest.php', 'tests/Unit/Security/UnserializeInAppNamesItsAllowedClassesTest.php'],
        "mutants": [
            ('OF1 the registration hands back the framework encrypter, so nothing is wrapped', [
                ('app/Providers/AppServiceProvider.php',
                 "            return (new \\App\\Support\\Security\\ObjectFreeEncrypter($encrypter->getKey(), $app['config']['app.cipher']))\n                ->previousKeys($encrypter->getPreviousKeys());\n",
                 '            return $encrypter;\n'),
            ]),
            ('OF2 the previous keys are not carried over', [
                ('app/Providers/AppServiceProvider.php',
                 "            return (new \\App\\Support\\Security\\ObjectFreeEncrypter($encrypter->getKey(), $app['config']['app.cipher']))\n                ->previousKeys($encrypter->getPreviousKeys());\n",
                 "            return new \\App\\Support\\Security\\ObjectFreeEncrypter($encrypter->getKey(), $app['config']['app.cipher']);\n"),
            ]),
            ('OF3 the cipher is not the configured one', [
                ('app/Providers/AppServiceProvider.php',
                 "$app['config']['app.cipher']))\n                ->previousKeys",
                 "'aes-256-gcm'))\n                ->previousKeys"),
            ]),
            ("OF4 the key is not the framework encrypter's", [
                ('app/Providers/AppServiceProvider.php',
                 'new \\App\\Support\\Security\\ObjectFreeEncrypter($encrypter->getKey(),',
                 'new \\App\\Support\\Security\\ObjectFreeEncrypter(random_bytes(32),'),
            ]),
            ('OF5 the bytes are not looked at before PHP reads them', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '        if (! self::isPlainData($decrypted)) {',
                 '        if (false) {'),
            ]),
            ('OF6 the framework unserializes first (the object is built before this class looks)', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '$decrypted = parent::decrypt($payload, false);',
                 '$decrypted = parent::decrypt($payload, true);'),
            ]),
            ('OF7 the `$unserialize = false` form is judged too', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '        if (! $unserialize) {\n            return $decrypted;\n        }\n\n',
                 ''),
            ]),
            ('OF8 a refusal leaves no trace', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "            Log::warning('a decrypted payload was not plain data and was refused');\n\n",
                 ''),
            ]),
            ('OF9 a refusal carries what it refused', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "Log::warning('a decrypted payload was not plain data and was refused');",
                 "Log::warning('a decrypted payload was not plain data and was refused', ['payload' => $decrypted]);"),
            ]),
            ("OF10 a refusal is not the framework's DecryptException", [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "throw new DecryptException('The payload is invalid.');",
                 "throw new \\RuntimeException('The payload is invalid.');"),
            ]),
            ('OF11 a refusal hands back nothing and goes on', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "            throw new DecryptException('The payload is invalid.');\n",
                 '            return null;\n'),
            ]),
            ('OF12 the second layer allows every class', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "return unserialize($decrypted, ['allowed_classes' => false]);",
                 "return unserialize($decrypted, ['allowed_classes' => true]);"),
            ]),
            ('OF13 the depth is not bounded', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 'private const MAX_DEPTH = 16;',
                 'private const MAX_DEPTH = 4096;'),
            ]),
            ('OF14 a nest of exactly the maximum depth is refused', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 'if ($depth > self::MAX_DEPTH) {',
                 'if ($depth >= self::MAX_DEPTH) {'),
            ]),
            ('OF15 a null needs no semicolon', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "return self::token($bytes, $at, '/\\GN;/');",
                 "return self::token($bytes, $at, '/\\GN/');"),
            ]),
            ('OF16 any digit is a boolean', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "'/\\Gb:[01];/'",
                 "'/\\Gb:[0-9];/'"),
            ]),
            ('OF17 an integer may have a leading zero', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "'/\\Gi:(-?)(0|[1-9]\\d{0,18});/'",
                 "'/\\Gi:(-?)(\\d{1,19});/'"),
            ]),
            ('OF18 minus zero is an integer', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 " || ($m[1] === '-' && $m[2] === '0')",
                 ''),
            ]),
            ('OF19 an integer past the range is accepted', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "        if (strlen($m[2]) === 19 && strcmp($m[2], $m[1] === '-' ? '9223372036854775808' : '9223372036854775807') > 0) {",
                 '        if (false) {'),
            ]),
            ('OF20 the largest integer is refused', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "'9223372036854775808' : '9223372036854775807') > 0",
                 "'9223372036854775808' : '9223372036854775807') >= 0"),
            ]),
            ('OF21 the smallest integer is refused', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "'9223372036854775808' : '9223372036854775807') > 0",
                 "'9223372036854775807' : '9223372036854775807') > 0"),
            ]),
            ('OF22 a float may have a leading zero', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '-?(?:0|[1-9]\\d*)(?:\\.\\d+)?',
                 '-?\\d+(?:\\.\\d+)?'),
            ]),
            ('OF23 NAN and the infinities are not floats', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "|NAN|-?INF);/'",
                 ");/'"),
            ]),
            ('OF24 a string length may have a leading zero', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '\'/\\Gs:(0|[1-9]\\d{0,8}):"/\'',
                 '\'/\\Gs:(\\d{1,9}):"/\''),
            ]),
            ('OF25 a string need not close', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 'if (substr($bytes, $end, 2) !== \'";\') {',
                 'if (false) {'),
            ]),
            ('OF26 a string is skipped one byte short', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '$at = $end + 2;',
                 '$at = $end + 1;'),
            ]),
            ('OF27 an array count may have a leading zero', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "'/\\Ga:(0|[1-9]\\d{0,8}):\\{/'",
                 "'/\\Ga:(\\d{1,9}):\\{/'"),
            ]),
            ('OF28 an array key may be a boolean', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "in_array($bytes[$at] ?? '', ['i', 's'], true)",
                 "in_array($bytes[$at] ?? '', ['i', 's', 'b'], true)"),
            ]),
            ('OF29 the last element of an array is not read', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 'for ($left = (int) $m[1]; $left > 0; $left--) {',
                 'for ($left = (int) $m[1]; $left > 1; $left--) {'),
            ]),
            ('OF30 an array need not close with a brace', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "                if (($bytes[$at] ?? '') !== '}') {\n                    return false;\n                }\n\n",
                 ''),
            ]),
            ('OF31 anything may follow the value', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 'return self::value($bytes, $at, 0) && $at === strlen($bytes);',
                 'return self::value($bytes, $at, 0);'),
            ]),
            ('OF32 a token is matched from the start of the input, not from where the reader is', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 'if (preg_match($pattern, $bytes, $m, 0, $at) !== 1) {',
                 'if (preg_match($pattern, $bytes, $m) !== 1) {'),
            ]),
            ('OF33 a token this reader does not know is plain data', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 '            default:\n                return false;\n',
                 '            default:\n                return true;\n'),
            ]),
            ('OF34 an object with no properties is plain data', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "            case 'N':\n",
                 '            case \'O\':\n                return self::token($bytes, $at, \'/\\GO:\\d+:"[^"]*":0:\\{\\}/\');\n\n            case \'N\':\n'),
            ]),
            ('OF35 an enum case is plain data', [
                ('app/Support/Security/ObjectFreeEncrypter.php',
                 "            case 'N':\n",
                 '            case \'E\':\n                return self::token($bytes, $at, \'/\\GE:\\d+:"[^"]*";/\');\n\n            case \'N\':\n'),
            ]),
            ('OF36 the ip-api.com answer is unserialized with every class allowed', [
                ('app/Http/Controllers/SettingController.php',
                 "$query = @unserialize(file_get_contents('http://ip-api.com/php/'.$ip), ['allowed_classes' => false]);",
                 "$query = @unserialize(file_get_contents('http://ip-api.com/php/'.$ip));"),
            ]),
        ],
    },
    {
        "key": 'settingname',
        "title": "P1-SEC-78 -- the value of a row is held to the sign-in path rule whatever spelling of its NAME reached the table: the name is judged as the column STORES it, PHP-visible spellings by the pattern, and a name PHP cannot judge is put to the engine, the way HostClaims does it",
        "tests": ['tests/Feature/Security/SettingNameAtTheWriterTest.php', 'tests/Feature/Security/HostClaimsTest.php'],
        "mutants": [
            ('SN1 the name is judged as POSTED, not as the column stores it', [
                ('app/Services/StoredPathPolicy.php',
                 "        $stored = HostClaims::asStored($name, $column['length']);\n",
                 '        $stored = $name;\n'),
            ]),
            ('SN2 the pattern is no longer applied: only a name PHP cannot judge is looked at', [
                ('app/Services/StoredPathPolicy.php',
                 "        if (preg_match('/_sign_in_image$/', strtolower(trim($stored))) === 1) {\n            return true;\n        }\n\n",
                 ''),
            ]),
            ('SN3 the pattern is unanchored (any name that merely CONTAINS _sign_in_image)', [
                ('app/Services/StoredPathPolicy.php',
                 "if (preg_match('/_sign_in_image$/', strtolower(trim($stored))) === 1) {",
                 "if (preg_match('/_sign_in_image/', strtolower(trim($stored))) === 1) {"),
            ]),
            ('SN4 the pattern is case-sensitive', [
                ('app/Services/StoredPathPolicy.php',
                 "if (preg_match('/_sign_in_image$/', strtolower(trim($stored))) === 1) {",
                 "if (preg_match('/_sign_in_image$/', trim($stored)) === 1) {"),
            ]),
            ('SN5 the pattern does not trim (surrounding spaces, and the control bytes trim() strips)', [
                ('app/Services/StoredPathPolicy.php',
                 "if (preg_match('/_sign_in_image$/', strtolower(trim($stored))) === 1) {",
                 "if (preg_match('/_sign_in_image$/', strtolower($stored)) === 1) {"),
            ]),
            ('SN6 a plain name is put to the engine too (a query for every save)', [
                ('app/Services/StoredPathPolicy.php',
                 "        if (HostClaims::plain($stored)) {\n            return false;\n        }\n\n",
                 ''),
            ]),
            ('SN7 a name that is not plain is never put to the engine', [
                ('app/Services/StoredPathPolicy.php',
                 '        if (HostClaims::plain($stored)) {\n',
                 '        if (true) {\n'),
            ]),
            ('SN8 the engine is asked about the name as POSTED, not as stored', [
                ('app/Services/StoredPathPolicy.php',
                 "[$sql, $bindings] = self::engineQuestion($stored, $column['collation']);",
                 "[$sql, $bindings] = self::engineQuestion($name, $column['collation']);"),
            ]),
            ('SN9 the engine is asked without trim()', [
                ('app/Services/StoredPathPolicy.php',
                 "'select lower(trim(?)) in ('",
                 "'select lower(?) in ('"),
            ]),
            ('SN10 the engine is asked without lower()', [
                ('app/Services/StoredPathPolicy.php',
                 "'select lower(trim(?)) in ('",
                 "'select trim(?) in ('"),
            ]),
            ('SN11 the writer rule knows only the Facebook row', [
                ('app/Services/StoredPathPolicy.php',
                 "public const SIGN_IN_ROWS = ['facebook_sign_in_image', 'linkedin_sign_in_image'];",
                 "public const SIGN_IN_ROWS = ['facebook_sign_in_image'];"),
            ]),
            ('SN12 the engine question has one placeholder for the names, however many there are', [
                ('app/Services/StoredPathPolicy.php',
                 'array_fill(0, count(self::SIGN_IN_ROWS), \'?\')',
                 'array_fill(0, 1, \'?\')'),
            ]),
            ('SN13 a save that changes neither name nor value is judged too', [
                ('app/Services/StoredPathPolicy.php',
                 "        if ($setting->exists && ! $setting->isDirty(['name', 'value'])) {\n            return;\n        }\n\n",
                 ''),
            ]),
            ('SN14 only a changed VALUE is judged (a rename keeps the value it had)', [
                ('app/Services/StoredPathPolicy.php',
                 "if ($setting->exists && ! $setting->isDirty(['name', 'value'])) {",
                 "if ($setting->exists && ! $setting->isDirty('value')) {"),
            ]),
            ('SN15 only a changed NAME is judged (a new value for a row already named so)', [
                ('app/Services/StoredPathPolicy.php',
                 "if ($setting->exists && ! $setting->isDirty(['name', 'value'])) {",
                 "if ($setting->exists && ! $setting->isDirty('name')) {"),
            ]),
            ('SN16 an existing row is never judged', [
                ('app/Services/StoredPathPolicy.php',
                 "if ($setting->exists && ! $setting->isDirty(['name', 'value'])) {",
                 "if ($setting->exists || ! $setting->isDirty(['name', 'value'])) {"),
            ]),
            ('SN17 the name judged is the one the row HAD, not the one it is being saved under', [
                ('app/Services/StoredPathPolicy.php',
                 'if (self::isSignInName((string) $setting->name)) {',
                 "if (self::isSignInName((string) \\$setting->getOriginal('name'))) {".replace('\\$', '$')),
            ]),
            ('SN18 the value is judged by asking about the NAME', [
                ('app/Services/StoredPathPolicy.php',
                 "            self::assert('value', $setting->value);\n        }\n    }\n}",
                 "            self::assert('value', $setting->name);\n        }\n    }\n}"),
            ]),
            ('SN19 HostClaims::plain says every name is plain', [
                ('app/Services/HostClaims.php',
                 "        return preg_match('/[^\\x21-\\x7E]/', trim($text, ' ')) !== 1;\n",
                 "        return true;\n"),
            ]),
            ('SN20 HostClaims::asStored keeps one character too many (256)', [
                ('app/Services/HostClaims.php',
                 "        return mb_substr($name, 0, $length, 'UTF-8');\n",
                 "        return mb_substr($name, 0, $length + 1, 'UTF-8');\n"),
            ]),
            ('SN21 HostClaims::asStored cuts the name by BYTES (the window of N characters is N bytes)', [
                ('app/Services/HostClaims.php',
                 "        $name = substr($name, 0, 4 * $length + 4);\n",
                 "        $name = substr($name, 0, $length);\n"),
            ]),
            ('SN22 HostClaims::asStored keeps N BYTES, not N characters', [
                ('app/Services/HostClaims.php',
                 "        return mb_substr($name, 0, $length, 'UTF-8');\n",
                 "        return substr($name, 0, $length);\n"),
            ]),
            ('SN23 an engine that gives no answer has said no', [
                ('app/Services/StoredPathPolicy.php',
                 '        return ($row->hit ?? 1) != 0;\n',
                 '        return ($row->hit ?? 0) != 0;\n'),
            ]),
            ('SN24 the live width of the column is not used', [
                ('app/Services/StoredPathPolicy.php',
                 "        $stored = HostClaims::asStored($name, $column['length']);\n",
                 '        $stored = HostClaims::asStored($name);\n'),
            ]),
            ('SN25 the engine is asked under the CONNECTION\'s collation, not the column\'s (the first form)', [
                ('app/Services/StoredPathPolicy.php',
                 "[$sql, $bindings] = self::engineQuestion($stored, $column['collation']);",
                 '[$sql, $bindings] = self::engineQuestion($stored, null);'),
            ]),
            ('SN26 the collation is put inside the function, where SQLite does not carry it', [
                ('app/Services/StoredPathPolicy.php',
                 "return ['select lower(trim(?))' . $by . ' in (' .",
                 "return ['select lower(trim(?' . $by . ')) in (' ."),
            ]),
            ('SN27 a string that is not a collation name is put into SQL', [
                ('app/Services/StoredPathPolicy.php',
                 "        if (preg_match('/\\Autf8mb4_[a-z0-9_]+\\z/', $collation) !== 1) {\n            throw",
                 "        if (false) {\n            throw"),
            ]),
            ('SN28 the column the test stands in for is ignored', [
                ('app/Services/StoredPathPolicy.php',
                 '        if (self::$nameColumnOverride !== null) {\n',
                 '        if (false) {\n'),
            ]),
        ],
    },
    {
        "key": 'richtext',
        "title": "P1-SEC-75 -- a merchant's Helpdesk ticket text reaches the operator's browser only through an allowlist sanitizer (two host-side view overrides) and an escaping subclass of the module's ticket list",
        "tests": ['tests/Unit/Support/Html/RichTextTest.php', 'tests/Feature/Security/HelpdeskStoredXssTest.php'],
        "mutants": [
            ('RT1 a script is unwrapped instead of dropped (its code reads as text)', [
                ('app/Support/Html/RichText.php',
                 "        'script', 'style', 'iframe',",
                 "        'style', 'iframe',"),
            ]),
            ('RT2 a stylesheet is unwrapped instead of dropped', [
                ('app/Support/Html/RichText.php',
                 "        'script', 'style', 'iframe',",
                 "        'script', 'iframe',"),
            ]),
            ('RT3 a link may use a script scheme', [
                ('app/Support/Html/RichText.php',
                 "self::url($value, ['http', 'https', 'mailto', 'tel'])",
                 "self::url($value, ['http', 'https', 'mailto', 'tel', 'javascript'])"),
            ]),
            ('RT4 a link may use a data scheme', [
                ('app/Support/Html/RichText.php',
                 "self::url($value, ['http', 'https', 'mailto', 'tel'])",
                 "self::url($value, ['http', 'https', 'mailto', 'tel', 'data'])"),
            ]),
            ('RT5 the characters a browser ignores are not taken out of a URL before its scheme is read', [
                ('app/Support/Html/RichText.php',
                 "$url = preg_replace(self::URL_NOISE, '', $value);",
                 '$url = $value;'),
            ]),
            ('RT6 a web link may have no host', [
                ('app/Support/Html/RichText.php',
                 "        if (($scheme === 'http' || $scheme === 'https') && preg_match(",
                 "        if (false && preg_match("),
            ]),
            ('RT7 an image may be an SVG', [
                ('app/Support/Html/RichText.php',
                 "#^data:image/(png|jpe?g|gif|webp);base64,",
                 "#^data:image/(png|jpe?g|gif|webp|svg\\+xml);base64,"),
            ]),
            ('RT8 an image with no acceptable source is written anyway', [
                ('app/Support/Html/RichText.php',
                 "        if ($tag === 'img' && ! str_contains($out, ' src=\"')) {\n            return null;\n        }\n",
                 ''),
            ]),
            ('RT9 text is not escaped', [
                ('app/Support/Html/RichText.php',
                 "                $out .= htmlspecialchars(str_replace(\"\\0\", '', $node->data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');\n",
                 "                $out .= str_replace(\"\\0\", '', $node->data);\n"),
            ]),
            ('RT10 a URL is not escaped inside its attribute', [
                ('app/Support/Html/RichText.php',
                 "' href=\"' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '\"'",
                 "' href=\"' . $url . '\"'"),
            ]),
            ('RT11 a title or an alt is not escaped inside its attribute', [
                ('app/Support/Html/RichText.php',
                 "htmlspecialchars(mb_substr($value, 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')",
                 "mb_substr($value, 0, 300)"),
            ]),
            ('RT12 a style declaration is copied, not matched and re-written', [
                ('app/Support/Html/RichText.php',
                 "            if (isset(self::STYLE[$property]) && preg_match(self::STYLE[$property], $setting) === 1) {",
                 "            if (true) {"),
            ]),
            ('RT13 a colour may be anything of letters', [
                ('app/Support/Html/RichText.php',
                 "'/^(#[0-9a-f]{3,8}|[a-z]{3,20}|rgba?\\(",
                 "'/^(#[0-9a-f]{3,8}|[a-z]{3,20}|[^;]{3,60}|rgba?\\("),
            ]),
            ('RT14 a link is written without rel and target', [
                ('app/Support/Html/RichText.php',
                 "        return $linked ? $out . ' rel=\"noopener noreferrer nofollow\" target=\"_blank\"' : $out;",
                 "        return $out;"),
            ]),
            ('RT15 the input is not cut at the limit', [
                ('app/Support/Html/RichText.php',
                 "        $html = substr($html, 0, self::MAX_BYTES);\n",
                 ''),
            ]),
            ('RT16 the depth limit is gone', [
                ('app/Support/Html/RichText.php',
                 "            if (! isset(self::ELEMENTS[$tag]) || $depth >= self::MAX_DEPTH) {\n                $out .= $inner;",
                 "            if (! isset(self::ELEMENTS[$tag])) {\n                $out .= $inner;"),
            ]),
            ('RT17 a null byte is kept in the text', [
                ('app/Support/Html/RichText.php',
                 "                $out .= htmlspecialchars(str_replace(\"\\0\", '', $node->data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');\n",
                 "                $out .= htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');\n"),
            ]),
            ('RT18 an element that is not on the list keeps nothing (its text is dropped too)', [
                ('app/Support/Html/RichText.php',
                 "            if (! isset(self::ELEMENTS[$tag]) || $depth >= self::MAX_DEPTH) {\n                $out .= $inner;\n\n                continue;\n            }",
                 "            if (! isset(self::ELEMENTS[$tag]) || $depth >= self::MAX_DEPTH) {\n                continue;\n            }"),
            ]),
            ('RT19 a colour that is not a colour reaches the badge', [
                ('app/Support/Html/RichText.php',
                 "        return preg_match(self::COLOR, $value) === 1 ? strtolower($value) : null;",
                 "        return $value === '' ? null : $value;"),
            ]),
            ('RT20 the ticket list is not the escaping subclass', [
                ('app/Providers/AppServiceProvider.php',
                 "        $this->app->bind(\\Workdo\\Helpdesk\\DataTables\\HelpdeskData::class, \\App\\DataTables\\SafeHelpdeskData::class);\n",
                 ''),
            ]),
            ('RT21 the name cell is not escaped', [
                ('app/DataTables/SafeHelpdeskData.php',
                 "'<span class=\"white-space\">' . e($ticket->name) . '</span>'",
                 "'<span class=\"white-space\">' . $ticket->name . '</span>'"),
            ]),
            ('RT22 the subject cell is not escaped', [
                ('app/DataTables/SafeHelpdeskData.php',
                 "'<span class=\"white-space\">' . e($ticket->subject) . '</span>'",
                 "'<span class=\"white-space\">' . $ticket->subject . '</span>'"),
            ]),
            ('RT23 the creator profile name is not escaped', [
                ('app/DataTables/SafeHelpdeskData.php',
                 "'<span class=\"text-primary\">' . e($ticket->createdBy?->name) . '</span>'",
                 "'<span class=\"text-primary\">' . $ticket->createdBy?->name . '</span>'"),
            ]),
            ('RT24 the category name is not escaped', [
                ('app/DataTables/SafeHelpdeskData.php',
                 "'>' . e($ticket->getTicketCategory->name ?? '') . '</span>'",
                 "'>' . ($ticket->getTicketCategory->name ?? '') . '</span>'"),
            ]),
            ('RT25 the status name is not escaped', [
                ('app/DataTables/SafeHelpdeskData.php',
                 "'>' . e($ticket->getTicketStatus->status_name ?? '') . '</span>'",
                 "'>' . ($ticket->getTicketStatus->status_name ?? '') . '</span>'"),
            ]),
            ('RT26 the badge background is written from the stored colour as it is', [
                ('app/DataTables/SafeHelpdeskData.php',
                 "        $color = RichText::cssColor($color);\n",
                 ''),
            ]),
            ('RT27 the conversation view prints the description raw', [
                ('resources/views/vendor/helpdesk/helpdesk_ticket/ticket_reply.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($ticket->description) !!}',
                 '{!! $ticket->description !!}'),
            ]),
            ('RT28 the conversation view prints a reply raw', [
                ('resources/views/vendor/helpdesk/helpdesk_ticket/ticket_reply.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($conversion->description) !!}',
                 '{!! $conversion->description !!}'),
            ]),
            ('RT29 the ticket page prints the description raw', [
                ('resources/views/vendor/helpdesk/helpdesk_ticket/show.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($ticket->description) !!}',
                 '{!! $ticket->description !!}'),
            ]),
            ('RT30 the ticket page prints a reply raw', [
                ('resources/views/vendor/helpdesk/helpdesk_ticket/show.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($conversion->description) !!}',
                 '{!! $conversion->description !!}'),
            ]),
            ('RT31 a link may carry a style', [
                ('app/Support/Html/RichText.php',
                 "'a' => ['href', 'title'], 'img' =>",
                 "'a' => ['href', 'title', 'style'], 'img' =>"),
            ]),
            ('RT32 a picture may carry a style', [
                ('app/Support/Html/RichText.php',
                 "'img' => ['src', 'alt', 'title', 'width', 'height'],",
                 "'img' => ['src', 'alt', 'title', 'width', 'height', 'style'],"),
            ]),
            ('RT33 a table cell may carry a width', [
                ('app/Support/Html/RichText.php',
                 "'td' => ['colspan', 'rowspan', 'style'],",
                 "'td' => ['colspan', 'rowspan', 'style', 'width'],"),
            ]),
            ('RT34 a list item may carry a style', [
                ('app/Support/Html/RichText.php',
                 "'li' => ['dir'],",
                 "'li' => ['dir', 'style'],"),
            ]),
            ('RT35 an element that is not on the list is allowed (section)', [
                ('app/Support/Html/RichText.php',
                 "'a' => ['href', 'title'], 'img' =>",
                 "'section' => [], 'a' => ['href', 'title'], 'img' =>"),
            ]),
            ('RT36 a style element is unwrapped instead of dropped', [
                ('app/Support/Html/RichText.php',
                 "'script', 'style', 'iframe',",
                 "'script', 'iframe',"),
            ]),
            ('RT37 a style may set a position', [
                ('app/Support/Html/RichText.php',
                 "        'text-align'       => '/^(left|right|center|justify|start|end)$/i',",
                 "        'position' => '/^(fixed|absolute)$/i',\n        'text-align'       => '/^(left|right|center|justify|start|end)$/i',"),
            ]),
            ('RT38 a text-align may be anything', [
                ('app/Support/Html/RichText.php',
                 "'text-align'       => '/^(left|right|center|justify|start|end)$/i',",
                 "'text-align'       => '/^.+$/',"),
            ]),
            ('RT39 a font-size may be anything', [
                ('app/Support/Html/RichText.php',
                 "'font-size'        => '/^\\d{1,3}(\\.\\d{1,2})?(px|pt|em|rem|%)$/i',",
                 "'font-size'        => '/^.+$/',"),
            ]),
            ('RT40 a width or a height may be anything, written as it is', [
                ('app/Support/Html/RichText.php',
                 "if (preg_match('/^\\d{1,4}$/', trim($value)) === 1) {",
                 "if (trim($value) !== '') {"),
            ]),
            ('RT41 a direction may be anything, written as it is', [
                ('app/Support/Html/RichText.php',
                 "if (preg_match('/^(ltr|rtl|auto)$/i', trim($value)) === 1) {",
                 "if (trim($value) !== '') {"),
            ]),
            ('RT42 a colspan, a rowspan or a start may be anything, written as it is', [
                ('app/Support/Html/RichText.php',
                 "if (preg_match('/^\\d{1,3}$/', trim($value)) === 1) {",
                 "if (trim($value) !== '') {"),
            ]),
            ('RT43 a crowded tag is parsed', [
                ('app/Support/Html/RichText.php',
                 'if (self::crowded($html)) {',
                 'if (false) {'),
            ]),
            ('RT44 the limit on a tag is raised', [
                ('app/Support/Html/RichText.php',
                 'public const MAX_TAG = 400;',
                 'public const MAX_TAG = 400000;'),
            ]),
            ('RT45 a byte libxml does not skip counts as a blank before a quote', [
                ('app/Support/Html/RichText.php',
                 'strspn($html, " \\t\\n\\r", $at + 1)',
                 'strspn($html, " \\t\\n\\r\\f", $at + 1)'),
            ]),
            ('RT46 an unterminated quote is taken for a quoted value', [
                ('app/Support/Html/RichText.php',
                 '                        if ($close !== false) {\n                            $at = $close + 1;\n\n                            continue;\n                        }\n',
                 '                        $at = $close === false ? $length : $close + 1;\n\n                        continue;\n'),
            ]),
            ('RT47 the input is cut at 256 KiB again', [
                ('app/Support/Html/RichText.php',
                 'public const MAX_BYTES = 2097152;',
                 'public const MAX_BYTES = 262144;'),
            ]),
            ('RT48 the picture cap is out of reach', [
                ('app/Support/Html/RichText.php',
                 'strlen($m[2]) > 1500000',
                 'strlen($m[2]) > 15000000'),
            ]),
            ('RT49 an alt or a title keeps its control characters', [
                ('app/Support/Html/RichText.php',
                 'mb_substr(self::printable($value), 0, 300)',
                 'mb_substr($value, 0, 300)'),
            ]),
            ('RT50 whitespace inside a colour is kept as it is', [
                ('app/Support/Html/RichText.php',
                 "strtolower(preg_replace('/\\s+/', ' ', $value)) : null;",
                 'strtolower($value) : null;'),
            ]),
            ('RT51 whitespace inside a style value is kept as it is', [
                ('app/Support/Html/RichText.php',
                 "$property . ': ' . strtolower(preg_replace('/\\s+/', ' ', $setting));",
                 "$property . ': ' . strtolower($setting);"),
            ]),
            ('RT52 input libxml cannot read is returned raw', [
                ('app/Support/Html/RichText.php',
                 "        if ($loaded === false) {\n            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');",
                 '        if ($loaded === false) {\n            return $html;'),
            ]),
            ('RT53 the category list is not the escaping subclass', [
                ('app/Providers/AppServiceProvider.php',
                 '        $this->app->bind(\\Workdo\\Helpdesk\\DataTables\\HelpdeskCategoryData::class, \\App\\DataTables\\SafeHelpdeskCategoryData::class);\n',
                 ''),
            ]),
            ('RT54 the status list is not the escaping subclass', [
                ('app/Providers/AppServiceProvider.php',
                 '        $this->app->bind(\\Workdo\\Helpdesk\\DataTables\\HelpdeskStatusData::class, \\App\\DataTables\\SafeHelpdeskStatusData::class);\n',
                 ''),
            ]),
            ('RT55 the category colour is written as it is', [
                ('app/DataTables/SafeHelpdeskCategoryData.php',
                 '$color = RichText::cssColor($color);',
                 "$color = $color === '' ? null : $color;"),
            ]),
            ('RT56 the status colour is written as it is', [
                ('app/DataTables/SafeHelpdeskStatusData.php',
                 '$color = RichText::cssColor($color);',
                 "$color = $color === '' ? null : $color;"),
            ]),
            ("RT57 the creator's name is read without the null-safe operator", [
                ('app/DataTables/SafeHelpdeskData.php',
                 'e($ticket->createdBy?->name)',
                 'e($ticket->createdBy->name)'),
            ]),
        ],
    },
    {
        "key": 'supportxss',
        "title": "P1-SEC-79 -- a shopper's support-ticket text and name reach the merchant's browser only through the sanitizer and e() (two first-party views and the ticket list)",
        "tests": ['tests/Feature/Security/SupportTicketStoredXssTest.php'],
        "mutants": [
            ('SX1 the merchant\'s ticket page prints the description raw', [
                ('resources/views/support/edit.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($ticket->description) !!}',
                 '{!! $ticket->description !!}'),
            ]),
            ('SX2 the merchant\'s ticket page prints a reply raw', [
                ('resources/views/support/edit.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($conversion->description) !!}',
                 '{!! $conversion->description !!}'),
            ]),
            ('SX3 the storefront page prints the description raw', [
                ('resources/views/front_end/pages/reply_support_ticket.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($ticket->description) !!}',
                 '{!! $ticket->description !!}'),
            ]),
            ('SX4 the storefront page prints a reply raw', [
                ('resources/views/front_end/pages/reply_support_ticket.blade.php',
                 '{!! \\App\\Support\\Html\\RichText::clean($conversion->description) !!}',
                 '{!! $conversion->description !!}'),
            ]),
            ('SX5 the shopper\'s name is not escaped in the merchant\'s list', [
                ('app/DataTables/SupportTicketDataTable.php',
                 "return $ticket->UserData ? e($ticket->UserData->name) : '';",
                 "return $ticket->UserData ? $ticket->UserData->name : '';"),
            ]),
            ('SX6 the status is not escaped in the merchant\'s list', [
                ('app/DataTables/SupportTicketDataTable.php',
                 "rounded-1\">'.e($ticket->status).'</span>';",
                 "rounded-1\">'.$ticket->status.'</span>';"),
            ]),
            ('SX7 the ticket number is not escaped in the merchant\'s list', [
                ('app/DataTables/SupportTicketDataTable.php',
                 ".e($ticket->ticket_id).'</a>'",
                 ".$ticket->ticket_id.'</a>'"),
            ]),
            ('SX8 the order number is not escaped in the merchant\'s list', [
                ('app/DataTables/SupportTicketDataTable.php',
                 ".e($ticket->orderData->product_order_id ?? '-').'</a>'",
                 ".($ticket->orderData->product_order_id ?? '-').'</a>'"),
            ]),
        ],
    },
]


def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def declared(label):
    for prefix, reason in DECLARED_SURVIVORS.items():
        if label.split(' ', 1)[0] == prefix:
            return reason
    return None


def run_tests(tests):
    # BYTES, decoded leniently: a failing assertion over an upload prints the file's bytes (a PNG header is not UTF-8), and `text=True`
    # raised UnicodeDecodeError in the middle of a group -- the run died with the mutant it was judging still applied until the
    # restore handler ran, and the rest of the group was never judged.
    result = subprocess.run(['php', 'artisan', 'test', *tests], capture_output=True)
    out = (result.stdout + result.stderr).decode('utf-8', errors='replace')
    summary = ' / '.join(re.findall(r'Tests:\s+(.*)', out)) or 'no summary line'
    failed = sorted({re.sub(r'\s+[\d.]+s\s*$', '', line.strip()[2:])[:70] for line in out.splitlines() if line.strip().startswith('⨯')})
    return result.returncode, summary, failed


def main(argv):
    if '--list' in argv:
        for group in GROUPS:
            print(f"{group['key']}: {group['title']}  [{len(group['mutants'])} mutants; tests: {', '.join(group['tests'])}]")
            for label, _ in group['mutants']:
                print(f"    {label}{'   (DECLARED SURVIVOR)' if declared(label) else ''}")
        return 0

    os.chdir(ROOT)
    keys = [g['key'] for g in GROUPS]
    wanted_groups = [a for a in argv if a in keys] or keys
    prefixes = [a for a in argv if a not in keys]
    unknown = [a for a in prefixes if not any(label.startswith(a) for g in GROUPS for label, _ in g['mutants'])]
    if unknown:
        print(f"unknown group or mutant prefix: {', '.join(unknown)} (groups: {', '.join(keys)}; see --list)")
        return 2

    # SIGTERM (a `timeout`, a killed terminal) must still restore the file being mutated.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(143))

    status = 0
    totals = []
    for group in GROUPS:
        if group['key'] not in wanted_groups:
            continue
        mutants = [(label, edits) for label, edits in group['mutants'] if not prefixes or any(label.startswith(p) for p in prefixes)]
        if not mutants:
            continue
        print(f"\n=== {group['key']}: {group['title']}", flush=True)

        files = sorted({edit[0] for _, edits in mutants for edit in edits})
        dirty = subprocess.run(['git', 'status', '--porcelain', '--', *files], capture_output=True, text=True)
        if dirty.returncode == 0 and dirty.stdout.strip():
            print(f"REFUSED: uncommitted changes in a file this group mutates, so a restore would have no known target:\n{dirty.stdout}")
            return 2

        code, summary, failed = run_tests(group['tests'])
        if code != 0:
            print(f"BASELINE IS NOT GREEN ({summary}; {'; '.join(failed)[:300]}) -- every mutant would look killed. Fix the tree first.")
            return 3
        print(f"baseline (unmutated): {summary}", flush=True)

        killed = survived_declared = 0
        for label, edits in mutants:
            touched = sorted({edit[0] for edit in edits})
            before = {f: sha(f) for f in touched}
            backup = {f: Path(f).read_bytes() for f in touched}
            outcome = None
            try:
                applied = True
                for edit in edits:
                    f, old, new = edit[:3]
                    expected = edit[3] if len(edit) > 3 else 1      # an optional fourth element: how many times the string is meant to occur (all are replaced)
                    source = Path(f).read_text()
                    if source.count(old) != expected:
                        print(f"[{label}] NOT APPLIED: the string to replace occurs {source.count(old)} times in {f}, not {expected}", flush=True)
                        applied = False
                        break
                    Path(f).write_text(source.replace(old, new))
                if applied and any(subprocess.run(['php', '-l', f], capture_output=True).returncode for f in touched if f.endswith('.php')):
                    print(f"[{label}] MUTANT DOES NOT PARSE", flush=True)
                    applied = False
                if applied:
                    code, summary, failed = run_tests(group['tests'])
                    outcome = 'killed' if code != 0 else 'survived'
                else:
                    status = max(status, 2)
            finally:
                for f in touched:
                    Path(f).write_bytes(backup[f])
                for f in touched:
                    if sha(f) != before[f]:
                        print(f"RESTORE DID NOT VERIFY for {f} -- STOP. Run: git checkout -- {f}")
                        sys.exit(9)
            if outcome is None:
                continue
            reason = declared(label)
            if outcome == 'killed' and reason is None:
                killed += 1
                print(f"[{label}] KILLED   | {summary} | {'; '.join(failed)[:260]}", flush=True)
            elif outcome == 'survived' and reason is not None:
                survived_declared += 1
                print(f"[{label}] SURVIVED, AS DECLARED | {summary}", flush=True)
            elif outcome == 'survived':
                status = max(status, 1)
                print(f"[{label}] SURVIVED -- UNDECLARED: no test notices this mutant | {summary}", flush=True)
            else:
                status = max(status, 1)
                print(f"[{label}] KILLED, BUT DECLARED A SURVIVOR: a test now notices it -- delete its declaration | {'; '.join(failed)[:200]}", flush=True)
        totals.append(f"{group['key']}: {killed} killed of {len(mutants)}" + (f", {survived_declared} declared survivor" if survived_declared else ''))

    print('\n' + '\n'.join(totals))
    print('OUTCOME SET IS AS DECLARED' if status == 0 else f'OUTCOME SET IS NOT AS DECLARED (exit {status})')
    return status


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
