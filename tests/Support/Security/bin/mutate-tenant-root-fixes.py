#!/usr/bin/env python3
"""
REVERT-AND-CONFIRM FOR THE "A ROOT OF 1 IS A TENANT" FIXES (P1-SEC-67b, P1-SEC-67c, P1-SEC-67d), FOR P1-SEC-65, FOR THE OWNER'S DETECTION QUERIES, AND FOR P1-SEC-72
======================================================================================================================================================================

WHY THIS IS A SCRIPT AND NOT A TEST. The project's rule is stricter than "a failing test first": after the fix, revert it and confirm the
test fails again -- a test that passes for the wrong reason is the one that has actually bitten here. A reverted fix cannot live in the
suite (it would have to edit application code while the suite runs), so the evidence lives here, as the project's rule for evidence that
cannot be a test requires. Until 2026-11-18 these mutants lived in a session scratchpad and the pull request quoted their counts; an audit
of that description pointed out that a reader could not re-run them. Now they can.

WHAT IT DOES. For each mutant below it edits ONE OR TWO REAL SOURCE FILES (an exact, single-occurrence string replacement), runs the named
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
    'I2': "TWO LOCKS, AND THIS REMOVES ONE. Without `mimes:` the validator no longer reads the bytes, but ThemeImageUpload::storedExtension() "
          "does, and upload_theme_image() stores nothing when it answers null -- with the same sentence, so no assertion can tell which lock "
          "refused. I3 removes the other lock alone and is killed; I4 removes both and is killed. A refusal that survives either lock failing "
          "is the design, not a gap in the tests.",
    'I20': "`mimes:` already refuses whatever is not a valid uploaded file (Validator::isValidFileInstance), with a sentence of the same "
           "shape, so the `file` rule in front of it changes nothing a caller can observe. It is kept for the reader.",
    'W5': "It asks the merchant question of the writer's CREATOR instead of the writer. For the six principal kinds the tests build "
          "(operator, merchant, a merchant's staff, a self-demoted merchant, their staff, staff the operator created) the two questions "
          "have the same answer. A user created BY a merchant's staff member separates them (UserController::store stamps created_by "
          "with the creator's own id); no test pins that case and its reachability is doubtful.",
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
        "title": "P1-SEC-72 -- upload_theme_image() stores images only, under the server's extension, in the store's folder; and its three first-party callers",
        "tests": ['tests/Feature/Security/ThemeImageUploadTypeTest.php'],
        "mutants": [
            ("I1 helper: the stored extension is the client's again", [
                ('app/Helper/helper.php',
                 '        $extension = $refusal === null ? \\App\\Services\\ThemeImageUpload::storedExtension($theme_image, (bool) $for_customer) : null;',
                 '        $extension = $refusal === null ? strtolower($theme_image->getClientOriginalExtension()) : null;'),
            ]),
            ('I2 rules: `mimes:` removed (the extension lock is still there)', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['required', 'file', 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],"),
            ]),
            ("I3 storedExtension(): reads the client's name instead of the bytes", [
                ('app/Services/ThemeImageUpload.php',
                 '        $guessed = strtolower((string) $file->guessExtension());',
                 '        $guessed = strtolower((string) $file->getClientOriginalExtension());'),
            ]),
            ('I4 both locks gone: no `mimes:` and the name decides the extension', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['required', 'file', 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],"),
                ('app/Services/ThemeImageUpload.php',
                 '        $guessed = strtolower((string) $file->guessExtension());',
                 '        $guessed = strtolower((string) $file->getClientOriginalExtension());'),
            ]),
            ('I5 rules: `extensions:` removed', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'max:' . self::maxKb($forCustomer)]],"),
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
                 "            if (empty($cover_image['status'])) {\n                return $this->error(['message' => $cover_image['message']], $cover_image['message']);\n            }\n",
                 ''),
            ]),
            ('I11 helper: the plan is charged before the file is looked at', [
                ('app/Helper/helper.php',
                 '        if (empty($theme_image)) {\n            return $return;\n        }\n',
                 '        if (empty($theme_image)) {\n            return $return;\n        }\n        Utility::updateStorageLimit($user_id ?? \\Auth::user()->creatorId(), is_object($theme_image) ? $theme_image->getSize() : 0);\n'),
            ]),
            ('I12 helper: over the plan, the stored file is left on disk', [
                ('app/Helper/helper.php',
                 "            Storage::disk('theme')->delete($save);\n",
                 ''),
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
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list]],"),
            ]),
            ('I17 helper: the named store is ignored (the panel guard decides the folder)', [
                ('app/Helper/helper.php',
                 '        $folder = (int) $store_id > 0 ? (int) $store_id : getCurrentStore();',
                 '        $folder = getCurrentStore();'),
            ]),
            ('I18 web payment settings: a refused image is not recorded', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 "            $this->refusedImages[$gatewayName !== '' ? $gatewayName : $imageKey] = (string) $uploadResult['message'];\n",
                 ''),
            ]),
            ('I19 web payment settings: the refusal list is not reset per request', [
                ('app/Http/Controllers/PaymentSettingController.php',
                 '        $this->refusedImages = [];\n\n        // Handle currency settings',
                 '        // Handle currency settings'),
            ]),
            ('I20 rules: `file` removed (`mimes:` refuses a non-file by itself)', [
                ('app/Services/ThemeImageUpload.php',
                 "            ['image' => ['required', 'file', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],",
                 "            ['image' => ['required', 'mimes:' . $list, 'extensions:' . $list, 'max:' . self::maxKb($forCustomer)]],"),
            ]),
            ('I21 the customer ceiling is raised', [
                ('app/Services/ThemeImageUpload.php',
                 '            return self::CUSTOMER_MAX_KB;\n        }\n\n        try {',
                 '            return 999999;\n        }\n\n        try {'),
            ]),
            ('I22 helper: the folder keeps its trailing slash', [
                ('app/Helper/helper.php',
                 "        $dir = rtrim('uploads/' . $folder, '/');",
                 "        $dir = 'uploads/' . $folder;"),
            ]),
            ('I23 avatar handler: a refusal answers 401', [
                ('app/Http/Controllers/Api/ApiController.php',
                 "                return $this->error(['message' => $cover_image['message']], $cover_image['message']);",
                 "                return $this->error(['message' => $cover_image['message']], $cover_image['message'], 401);"),
            ]),
            ('I24 storedExtension(): the list is not consulted', [
                ('app/Services/ThemeImageUpload.php',
                 "        return $guessed !== '' && in_array($guessed, self::types($forCustomer), true) ? $guessed : null;",
                 "        return $guessed !== '' ? $guessed : null;"),
            ]),
            ('I25 avatar handler: the replaced avatar is unlinked without a credit', [
                ('app/Http/Controllers/Api/ApiController.php',
                 '            Utility::changeStorageLimit($store->created_by, $user->profile_image);',
                 '            \\App\\Services\\StoredUpload::delete($user->profile_image, $store->created_by);'),
            ]),
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
    result = subprocess.run(['php', 'artisan', 'test', *tests], capture_output=True, text=True)
    out = result.stdout + result.stderr
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
                for f, old, new in edits:
                    source = Path(f).read_text()
                    if source.count(old) != 1:
                        print(f"[{label}] NOT APPLIED: the string to replace occurs {source.count(old)} times in {f}", flush=True)
                        applied = False
                        break
                    Path(f).write_text(source.replace(old, new, 1))
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
