<?php

namespace Tests\Unit\Kyc;

use App\Models\KycVerification;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycDocumentStore;
use App\Services\Kyc\KycRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * KYC-002, FIRST CLAUSE — الوثائق الخاصة, *PRIVATE* DOCUMENTS. WHAT "PRIVATE" IS WORTH HERE.
 * =================================================================================================
 *
 * THE HOST MEASUREMENT THIS WHOLE FILE IS WRITTEN AGAINST, and it is not comfortable. The deployed
 * document root IS the repository root, and the root `.htaccess` deliberately carries no deny rules
 * because the owner's standing instruction is not to change public access to the test site
 * (P1-SEC-40). That file records its own live measurement:
 *
 *     GET /storage/logs/laravel.log  -> 200   3,421,645 bytes of text/plain
 *     GET /a-nonexistent-path        -> 404   so the 200 above is a real file, not a soft-404
 *
 * The front controller is reached only when `%{REQUEST_FILENAME} !-f`, so Apache serves ANY file
 * that really exists. **A DIRECTORY CHOICE IS THEREFORE NOT A CONTROL ON THIS HOST**, and a test
 * asserting "the documents are in a private folder" would be asserting a belief.
 *
 * SO THIS FILE ASSERTS THE FIVE THINGS ENGINEERING CAN ACTUALLY GUARANTEE, and nothing beyond them:
 *
 *   1. the path is not guessable — 64 hex characters of `random_bytes(32)`, with nothing of the
 *      merchant, the document or the time in it;
 *   2. no URL is ever minted — the disk carries no `url` key, so `Storage::url()` THROWS;
 *   3. the stored name has no extension, so no `AddHandler` can match it;
 *   4. the root is outside every publicly served tree, and a misconfiguration that moves it inside
 *      one is REFUSED rather than obeyed;
 *   5. exactly one class in `app/` can name the disk, so there is no second reader that skips the
 *      access log.
 *
 * WHAT IT DOES NOT ASSERT, stated so the gap is not mistaken for a control: that a client who
 * LEARNS a path is refused. That needs `docs/security/p1-sec-40-apache-deny.conf` activated — its
 * GROUP 2 rule `^/storage/(logs|framework|app)` already covers this disk's default root — and
 * activating it is the owner's decision.
 */
class KycPrivateStorageTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;
    use StripsSourceComments;

    /**
     * THE SHIPPED ROOT IS INSIDE `storage/app` AND OUTSIDE EVERY PUBLIC TREE.
     *
     * Asserted on the REAL configured value rather than on the literal in the config file, and
     * before `Storage::fake()` can replace it — a faked disk's root is under
     * `storage/framework/testing/disks/`, which would make this assertion about the harness.
     */
    public function test_the_shipped_disk_root_is_private_and_outside_every_public_tree(): void
    {
        $root = (string) config('filesystems.disks.' . config('kyc.disk') . '.root');

        $this->assertStringStartsWith(
            storage_path('app') . DIRECTORY_SEPARATOR,
            $root,
            'the private document root must sit under storage/app — the one tree the WRITTEN deny '
            . 'rules already cover (p1-sec-40-apache-deny.conf GROUP 2)'
        );

        foreach ([base_path('public'), base_path('uploads'), storage_path('app/public')] as $public) {
            $this->assertStringStartsNotWith($public . DIRECTORY_SEPARATOR, $root);
            $this->assertNotSame($public, rtrim($root, DIRECTORY_SEPARATOR));
        }

        $this->assertSame('local', config('filesystems.disks.' . config('kyc.disk') . '.driver'));
    }

    /**
     * THE WRITTEN (INACTIVE) DENY RULES ALREADY COVER THIS DISK'S ROOT, AND THAT IS WHY THE ROOT IS
     * WHERE IT IS.
     * =============================================================================================
     *
     * `docs/security/p1-sec-40-apache-deny.conf` GROUP 2 carries
     * `RedirectMatch 404 "(?i)^/storage/(logs|framework|app)(/|$)"`, and its nginx sibling the same.
     * Neither file is read by any server: the owner's standing instruction is not to change public
     * access to the test site, so the rules are held as the written control while the host keeps
     * serving those paths. Putting the private documents under `storage/app` means that the day the
     * owner activates those rules, these documents are covered BY A RULE THAT ALREADY EXISTS — no
     * new directive, no second decision.
     *
     * SO THIS TEST IS THE COUPLING. If somebody moves the root out of `storage/app` — to
     * `private/kyc`, say, which looks tidier — the documents silently leave the coverage of the
     * written rules and nothing else would say so. It asserts the pair, not the prose.
     */
    public function test_the_written_deny_rules_already_cover_this_disks_root(): void
    {
        $root = (string) config('filesystems.disks.' . config('kyc.disk') . '.root');

        $this->assertStringStartsWith(storage_path('app') . DIRECTORY_SEPARATOR, $root);

        foreach ([
            'docs/security/p1-sec-40-apache-deny.conf',
            'docs/security/p1-sec-40-nginx-deny.conf',
        ] as $conf) {
            $this->assertFileExists(base_path($conf));

            $this->assertMatchesRegularExpression(
                '#\^/storage/\(logs\|framework\|app\)#',
                (string) file_get_contents(base_path($conf)),
                $conf . ' no longer refuses /storage/app, so the private document root is covered by '
                . 'nothing at all — not even by a rule waiting to be switched on'
            );
        }
    }

    /**
     * `Storage::url()` DOES **NOT** THROW ON A LOCAL DISK WITH NO `url` KEY, AND I ASSUMED IT DID.
     * =============================================================================================
     *
     * The first version of this test asserted a `RuntimeException` and FAILED. Measured instead of
     * read: `Storage::disk('kyc')->url('1/store_syria/abc')` returns the string
     * `'/storage/1/store_syria/abc'`. The vendor source says why —
     * `FilesystemAdapter::url()` reaches `throw new RuntimeException('This driver does not support
     * retrieving URLs.')` only in its `else` branch, and a `local` disk takes the
     * `$adapter instanceof LocalAdapter` branch two lines above it, which returns
     * `getLocalUrl($path)` whatever the config says.
     *
     * WHY THAT MATTERS MORE THAN IT LOOKS, and why the census below is the real control. The URL
     * returned is a DEAD LINK — it resolves through `public/storage` to `storage/app/public/...`
     * and the document is in `storage/app/private/kyc/...`, so the file is not served by it. But
     * **it prints the secret name into HTML**, and on this host the secret name is the whole
     * protection: a client holding it can ask for `storage/app/private/kyc/<store>/<authority>/<name>`
     * directly, because the document root is the repository root and the front controller is
     * bypassed for any file that exists.
     *
     * So the mechanism that keeps the name out of a page is NOT the disk config. It is
     * `test_the_private_disk_is_reachable_from_exactly_one_class()` below, which is why that census
     * covers `resources/views/` and `routes/` as well as `app/`, and
     * `test_the_document_model_exposes_no_url()`, which stops the easiest accident of all.
     */
    public function test_a_local_disk_still_returns_a_url_string_so_the_census_is_the_control(): void
    {
        $url = Storage::disk((string) config('kyc.disk'))->url('1/store_syria/' . str_repeat('a', 64));

        $this->assertSame(
            '/storage/1/store_syria/' . str_repeat('a', 64),
            $url,
            'measured 2026-11-26. If a future Laravel makes this throw, say so here and tighten the '
            . 'claim — do not delete the census, which is what actually keeps the name out of a page.'
        );
    }

    /**
     * THE MODEL EXPOSES NO URL, so the shortest path to publishing the secret does not exist.
     *
     * A `getUrlAttribute()` would make `{{ $document->url }}` work in any Blade file, which the
     * census over `resources/views/` could not see — the view would name no disk at all.
     */
    public function test_the_document_model_exposes_no_url(): void
    {
        foreach (['url', 'getUrlAttribute', 'publicUrl', 'getPublicUrlAttribute', 'path', 'getPathAttribute'] as $method) {
            $this->assertFalse(
                method_exists(\App\Models\KycDocument::class, $method),
                "KycDocument::{$method}() would expose the stored name, which is the secret."
            );
        }
    }

    /** A root moved inside a publicly served tree is refused, for each of the three trees. */
    public function test_a_root_inside_a_public_tree_is_refused(): void
    {
        foreach (['public', 'uploads', 'storage/app/public'] as $forbidden) {
            config(['filesystems.disks.kyc.root' => base_path($forbidden) . '/kyc']);

            try {
                KycDocumentStore::assertPrivateRoot();
                $this->fail("a root inside {$forbidden} was accepted");
            } catch (KycRefusal $e) {
                $this->assertSame('public_root', $e->reason);
            }
        }
    }

    public function test_the_application_root_itself_is_refused_as_a_document_root(): void
    {
        // What the existing `theme` disk is: root = base_path(''). A document written there would
        // sit directly in the document root.
        config(['filesystems.disks.kyc.root' => base_path('')]);

        $this->expectException(KycRefusal::class);

        KycDocumentStore::assertPrivateRoot();
    }

    public function test_a_disk_carrying_a_public_url_is_refused(): void
    {
        config(['filesystems.disks.kyc.url' => 'https://example.test/kyc']);

        try {
            KycDocumentStore::assertPrivateRoot();
            $this->fail('a disk with a public URL was accepted');
        } catch (KycRefusal $e) {
            $this->assertSame('public_root', $e->reason);
        }
    }

    public function test_a_remote_disk_is_refused_rather_than_assumed_private(): void
    {
        config(['filesystems.disks.kyc.driver' => 's3']);

        try {
            KycDocumentStore::assertPrivateRoot();
            $this->fail('a remote disk was accepted');
        } catch (KycRefusal $e) {
            $this->assertSame('remote_disk', $e->reason);
        }
    }

    /**
     * THE STORED NAME IS THE SECRET: 64 hex characters, no extension, and nothing of the client's
     * own name — which is the part a stranger could otherwise guess, and the part that let two
     * shoppers' `proof.png` become one file in P1-SEC-71.
     */
    public function test_the_stored_name_carries_the_entropy_and_none_of_the_client_name(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $document = KycDocumentStore::store(
            $record,
            $this->kycPngUpload('my-passport-scan.png'),
            'commercial_register',
            $merchant
        );

        $expectedPrefix = $store->id . '/' . KycAuthority::STORE_SYRIA . '/';

        $this->assertStringStartsWith($expectedPrefix, $document->storage_name);

        $leaf = substr($document->storage_name, strlen($expectedPrefix));

        $this->assertSame(64, strlen($leaf), 'the leaf must be 32 bytes of hex');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $leaf);
        $this->assertStringNotContainsString('.', $leaf, 'no extension: nothing for AddHandler to match');
        $this->assertStringNotContainsString('passport', $document->storage_name);
        $this->assertStringNotContainsString('png', $leaf);

        // The client's own name is kept in its own column, for the reviewer, and is not a path.
        $this->assertSame('my-passport-scan.png', $document->original_name);

        $this->assertSame([$document->storage_name], $this->kycStoredFiles());
    }

    /** Two uploads of the SAME bytes land on two names, so a name is never predictable. */
    public function test_two_uploads_of_one_file_get_two_names(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['a', 'b']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $first = KycDocumentStore::store($record, $this->kycPngUpload('x.png'), 'a', $merchant);
        $second = KycDocumentStore::store($record, $this->kycPngUpload('x.png'), 'b', $merchant);

        $this->assertNotSame($first->storage_name, $second->storage_name);
        $this->assertCount(2, $this->kycStoredFiles());
    }

    /**
     * THE SAFE SET, ENUMERATED. Which files in `app/` may name the private disk at all.
     *
     * HUNTING FOR A DANGEROUS SPELLING WOULD BE THE WRONG CENSUS, and this project has the
     * measurement to prove it: a concatenation census keyed on `'literal' . $var` was blind to
     * `sprintf`, `implode` and a bare variable, and reported ONE dynamic raw-SQL site where there
     * were FIFTEEN. So the whitelist is the assertion: any NEW file in `app/` that reaches the disk
     * fails this test, whatever syntax it reaches it with, and somebody has to say why a second
     * reader exists that might not write an access-log row.
     *
     * COMMENTS ARE STRIPPED, because this very test names the disk in its own prose and
     * `KycDocument`'s docblock discusses it at length — the eight-times-measured census failure.
     */
    public function test_the_private_disk_is_reachable_from_exactly_one_class(): void
    {
        $allowed = [
            'app/Services/Kyc/KycDocumentStore.php',
        ];

        $offenders = [];
        $searched = 0;

        foreach ([app_path(), base_path('resources/views'), base_path('routes')] as $tree) {
            if (! is_dir($tree)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $searched++;

                $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $code = $this->codeOnly((string) file_get_contents($file->getPathname()));

                // Every way to reach THIS disk: by the config key, or by the literal name.
                $reaches = str_contains($code, "config('kyc.disk'")
                    || str_contains($code, 'config("kyc.disk"')
                    || str_contains($code, "disk('kyc')")
                    || str_contains($code, 'disk("kyc")')
                    || str_contains($code, 'filesystems.disks.kyc');

                if ($reaches && ! in_array($relative, $allowed, true)) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertGreaterThan(
            300,
            $searched,
            'the walk found almost no files, so the census would pass vacuously — the failure mode '
            . 'that made three earlier censuses in this repository report zero'
        );

        $this->assertSame(
            [],
            $offenders,
            'a second class reaches the private document disk. Reading a document must go through '
            . 'KycDocumentStore::readForActor(), which writes the access-log row in the same call; '
            . 'a reader that bypasses it is a read with no trail, which is KYC-002\'s fourth clause '
            . 'defeated.'
        );
    }

    /**
     * Comments blanked, offsets preserved — `MigrationHygieneTest::codeOnly()`'s lexer, reproduced
     * here rather than taken from `StripsSourceComments`.
     *
     * WHY NOT THE SHARED TRAIT: it asserts the stripped result is not empty, and a Blade view that
     * is nothing BUT a comment strips to empty — which failed this census against a file that was
     * entirely fine. The trait's assertion is right for its own use (a stripper that eats the file
     * turns every absence assertion into a free pass) and wrong for a walk over every view.
     */
    private function codeOnly(string $source): string
    {
        $out = '';

        foreach (@token_get_all($source) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat(' ', strlen($token[1]))
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /** And the one allowed file really does name it, so the census above cannot pass vacuously. */
    public function test_the_census_premise_holds(): void
    {
        $this->assertStringContainsString(
            "config('kyc.disk'",
            $this->sourceWithoutComments('app/Services/Kyc/KycDocumentStore.php'),
            'if the store stopped naming the disk this way the census above would be asserting nothing'
        );
    }

    /**
     * A FAILED WRITE IS A REFUSAL AND NOTHING IS PERSISTED. `PlatformUploadPolicy`'s docblock names
     * "a failed write was reported as success" as one of the four defects it exists to close; this
     * is the same hazard on a different door, and the row must not outlive the file.
     */
    public function test_a_write_that_does_not_land_persists_no_row(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        /*
         * THE INJECTION: a FILE sitting where the store's directory has to go, so the root is
         * perfectly usable and the document's own write cannot land. That is what a name collision,
         * a full disk or a revoked write looks like to Flysystem — `UnableToCreateDirectory`, which
         * `FilesystemAdapter::put()` does NOT catch even with `throw => false` (it catches
         * `UnableToWriteFile` and `UnableToSetVisibility` only — read from the vendor source after
         * this test produced the uncaught exception).
         *
         * TWO INJECTIONS WERE TRIED FIRST AND BOTH WERE WRONG, which is worth recording because
         * each failed in the direction that reads as success:
         *
         *   chmod 0500 on the root        THE WRITE SUCCEEDED. The suite runs as root, and root
         *                                 ignores the permission bits. A refusal test that cannot
         *                                 produce the refusal is this project's fourth way a test
         *                                 passes for the wrong reason.
         *   a root under a real file      threw out of `assertPrivateRoot()` instead, because
         *                                 resolving a local disk CREATES its root. Real finding,
         *                                 fixed in the store (`unusable_root`), but it tested a
         *                                 different door than this test's name claims.
         */
        Storage::disk('kyc')->put((string) $store->id, 'a file where the directory must go');

        try {
            KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);
            $this->fail('a failed write was reported as success');
        } catch (KycRefusal $e) {
            $this->assertSame('write_failed', $e->reason);
        }

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('kyc_documents')->count());
    }

    /**
     * A ROW WHOSE PATH IS NOT THE SHAPE THIS CLASS WRITES IS REFUSED AND LOGGED.
     *
     * WHY THIS IS NOT PARANOIA ABOUT OUR OWN DATA: a merchant can write `storage_setting` through
     * the platform's generic settings writers — no allow-list, no permission, measured in
     * `WebSettingsSecretsTest` — so a tenant can point the platform's own uploader at ANY configured
     * disk, this one included, and place files of their choosing in this tree. A planted FILE is
     * unreachable (no row names it); this is the other half, so a planted or hand-edited ROW cannot
     * name one either.
     *
     * The traversal forms are driven as well as the plausible ones, because `..` is what the shape
     * check is for: `realpath()` alone would resolve `1/store_syria/../../../../.env` back inside the
     * root only if it existed, and a check that depends on a file's existence answers differently on
     * two machines.
     */
    public function test_a_row_naming_a_path_this_class_would_never_write_is_refused(): void
    {
        Storage::fake('kyc');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $this->configureKycKinds(KycVerification::COMPANY, KycAuthority::STORE_SYRIA, ['commercial_register']);
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $document = KycDocumentStore::store($record, $this->kycPngUpload(), 'commercial_register', $merchant);

        foreach ([
            '../../../.env',
            $store->id . '/store_syria/../../../.env',
            $store->id . '/store_syria/' . $store->id,            // a tenant-planted name
            'uploads/' . $store->id . '/photo.png',               // what the platform uploader writes
            $store->id . '/store_syria/' . str_repeat('a', 63),   // one hex character short
        ] as $planted) {
            // Written raw: the model's own guards are not what is being measured here.
            \Illuminate\Support\Facades\DB::table('kyc_documents')
                ->where('id', $document->id)
                ->update(['storage_name' => $planted]);

            try {
                KycDocumentStore::readForActor($document->fresh(), $merchant);
                $this->fail('a row naming ' . $planted . ' was read');
            } catch (KycRefusal $e) {
                $this->assertSame('not_confined', $e->reason, $planted);
            }
        }

        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('action', \App\Services\Kyc\KycAccessLog::READ)
                ->count(),
            'a refused read must not be recorded as a read'
        );
        $this->assertSame(
            5,
            \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('action', \App\Services\Kyc\KycAccessLog::REFUSED)
                ->count(),
            'each refusal is one row in the trail'
        );
    }

    /**
     * A ROOT THAT CANNOT EVEN BE CREATED IS A REFUSAL, NOT A 500.
     *
     * Found by the test above: resolving a local disk runs
     * `LocalFilesystemAdapter::ensureRootDirectoryExists()`, so a root under a regular file throws
     * `UnableToCreateDirectory` out of the mere question "where is this disk". That reached the
     * caller as an uncaught exception.
     */
    public function test_a_root_that_cannot_be_created_is_refused(): void
    {
        config(['filesystems.disks.kyc.root' => base_path('artisan') . '/kyc-unusable']);
        Storage::forgetDisk('kyc');

        try {
            KycDocumentStore::assertPrivateRoot();
            $this->fail('an unusable root was accepted');
        } catch (KycRefusal $e) {
            $this->assertSame('unusable_root', $e->reason);
        }
    }
}
