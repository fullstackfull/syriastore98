<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\PlatformUploadPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KYC-002 — THE ONLY PLACE IN `app/` THAT TOUCHES A PRIVATE IDENTITY DOCUMENT'S BYTES.
 * =================================================================================================
 *
 * WHAT "PRIVATE" CAN AND CANNOT MEAN ON THIS HOST, measured rather than hoped for. The deployed
 * document root IS the repository root and the root `.htaccess` deliberately carries NO deny rules —
 * the owner's standing instruction is *"DO NOT block or change public access to the test website"*,
 * recorded in that file and in `docs/security/p1-sec-40-apache-deny.conf`. That file's own live
 * measurement is the proof: `GET /storage/logs/laravel.log -> 200`, 3,421,645 bytes of text/plain.
 * The front-controller rewrite is guarded by `RewriteCond %{REQUEST_FILENAME} !-f`, so Apache serves
 * ANY file that really exists instead of routing the request to Laravel.
 *
 * SO A DIRECTORY CHOICE IS NOT A CONTROL HERE, AND THIS CLASS DOES NOT PRETEND OTHERWISE:
 *
 *   the path is not guessable        `storage_name` is `<store>/<authority>/` plus 64 hex characters
 *                                   of `random_bytes(32)`. 2^256. Nothing about the document — not
 *                                   the merchant's name, not the kind, not the upload time — is in
 *                                   it, so it cannot be narrowed by knowing the victim.
 *   no URL is ever minted            the `kyc` disk carries no `url` key, so `Storage::url()` throws
 *                                   rather than returning an address. `KycPrivateStorageTest`
 *                                   executes that throw.
 *   no extension to act on           the stored name has none, so `AddHandler` has nothing to match
 *                                   and a browser opened on the file is told what to do by this
 *                                   application's own `Content-Type`, never by the file name.
 *   outside every public tree        `assertPrivateRoot()` refuses a root inside `public/`,
 *                                   `uploads/` or `storage/app/public`, and refuses `base_path()`
 *                                   itself — which is what the existing `theme` disk is.
 *   **NOT** refused to a client      who already knows the path. That needs the written deny rules
 *                                   activated, which is the owner's decision. Stated, not hidden.
 *
 * READING THE BYTES WRITES THE LOG ROW, IN THE SAME CALL. `readForActor()` is the only reader and it
 * records `KycAccessLog::READ` before it returns. That is a structural guarantee rather than a
 * convention: there is no second method that returns the bytes, and
 * `KycPrivateStorageTest::test_the_kyc_disk_is_named_in_exactly_one_class` pins the whitelist of
 * files allowed to name the disk at all — the SAFE set enumerated, which is this project's recorded
 * census rule after a dangerous-spelling census missed fourteen of fifteen sites.
 *
 * WHAT THIS CLASS DOES NOT DO:
 *
 *   - IT DOES NOT AUTHORIZE. `KycAccess` decides who may upload, read or review; every caller asks
 *     it first. A store service that also authorized would be two rules in one place and the second
 *     door would get one of them wrong.
 *   - IT DOES NOT DELETE. There is deliberately no delete method and no delete door: how long
 *     identity papers are kept, and what happens to them when a store closes, is an owner decision
 *     (`D-P6-46`) and this project's standing rule is that a destructive operation on personal data
 *     is an escalation. A replaced document is marked `superseded`, not removed.
 *   - IT DOES NOT ENCRYPT AT REST. It could not meaningfully: `APP_KEY` is in this repository's git
 *     history and unrotated, so `Crypt::encrypt()` is an encoding and not a control — the same
 *     finding `DownloadGrant` and `OrderDocumentAccessGuard` both record. Encryption under a
 *     SEPARATE key is a credential decision for the owner, named in the report rather than invented
 *     here.
 */
final class KycDocumentStore
{
    /** Roots a private document must never be written under, each resolved before comparison. */
    private const FORBIDDEN_ROOTS = ['public', 'uploads', 'storage/app/public'];

    private function __construct()
    {
    }

    public static function disk(): string
    {
        return (string) config('kyc.disk', 'kyc');
    }

    /**
     * REFUSE A DISK THAT IS NOT A PRIVATE LOCAL DIRECTORY, before a single byte is written.
     *
     * Called at the top of every write and every read, not once at boot: the configuration is
     * per-request in this application (a `.env` change, a `config:cache` that captured a different
     * value) and a check that ran at boot would be a check that ran against a different answer.
     *
     * LOCAL ONLY, AND THAT IS A STATED LIMIT RATHER THAN AN OVERSIGHT. A remote disk's access
     * control belongs to the bucket policy, which cannot be verified from this container — there are
     * no bucket credentials here. `DownloadGrant` records exactly this as the "remote sibling" of
     * its own finding, and shipping an unverified claim about a bucket's ACL on the identity-document
     * path would be worse than refusing it.
     *
     * @throws KycRefusal
     */
    public static function assertPrivateRoot(): void
    {
        $disk = self::disk();
        $driver = config('filesystems.disks.' . $disk . '.driver');

        if ($driver !== 'local') {
            throw new KycRefusal(
                'remote_disk',
                'private documents are stored on a local private disk only; the configured disk is not one'
            );
        }

        if (config('filesystems.disks.' . $disk . '.url') !== null) {
            throw new KycRefusal(
                'public_root',
                'the private document disk must not carry a public URL'
            );
        }

        $root = self::rootOf($disk);

        if ($root === '' || $root === self::canonicalise(base_path())) {
            throw new KycRefusal('public_root', 'the private document disk has no root of its own');
        }

        foreach (self::FORBIDDEN_ROOTS as $forbidden) {
            $resolved = self::canonicalise(base_path($forbidden));

            if ($resolved !== '' && ($root === $resolved || str_starts_with($root, $resolved . DIRECTORY_SEPARATOR))) {
                throw new KycRefusal(
                    'public_root',
                    'the private document disk resolves inside a publicly served directory'
                );
            }
        }
    }

    /**
     * Why this upload cannot be stored, or null.
     *
     * TWO GATES, AND THE FIRST ONE IS THE POLICY-FREE REFUSAL THAT MATTERS MOST.
     *
     *  1. THE KIND MUST BE ONE THE OWNER HAS DECIDED TO COLLECT. `config('kyc.document_kinds')`
     *     ships EMPTY for every subject type and every authority, so with no owner decision in place
     *     EVERY upload is refused with `no_kinds`. That is not a stub: identity papers are sensitive
     *     personal data under this project's standing safety boundary, and a mechanism that
     *     collected them before anybody decided which ones were required would be collecting data
     *     nobody asked for. The door is built, gated, logged — and closed until `D-P6-46` is
     *     answered.
     *  2. THE FILE ITSELF goes through `PlatformUploadPolicy::refusal()` — the platform's hardened
     *     uploader rule — with the CLIENT'S OWN NAME as the name under judgement and
     *     `config('kyc.accepted_extensions')` as the list. That is the right way to use it here: it
     *     applies the structural name rules (no control or format character, no script-capable last
     *     extension, no `AddHandler`-mapped extension inside the name), the signature check
     *     (`mimes:`), the client-extension check (`extensions:`) and the size ceiling, and it holds
     *     the client's extension to our list. The name we actually STORE is server-generated and has
     *     no extension at all, so it is not what needs judging.
     */
    public static function refusalFor(mixed $file, KycVerification $profile, string $kind): ?KycRefusal
    {
        $kinds = KycAuthority::documentKinds((string) $profile->subject_type, (string) $profile->authority);

        if ($kinds === []) {
            return new KycRefusal(
                'no_kinds',
                (string) __('No verification document types are configured for this account yet.')
            );
        }

        if (! in_array($kind, $kinds, true)) {
            return new KycRefusal(
                'unknown_kind',
                (string) __('That is not a document type this account can supply.')
            );
        }

        $accepted = (array) config('kyc.accepted_extensions', []);
        $maxKb = max(1, (int) config('kyc.max_kilobytes', 8192));

        $sentence = PlatformUploadPolicy::refusal(
            $file,
            $file instanceof UploadedFile ? (string) $file->getClientOriginalName() : '',
            implode(',', array_filter($accepted, 'is_string')),
            $maxKb
        );

        return $sentence === null ? null : new KycRefusal('file', $sentence);
    }

    /**
     * Store one private document against a verification record, and write the access-log row.
     *
     * THE ORDER OF OPERATIONS IS NOT ARBITRARY.
     *
     *   refuse first         nothing is written for a refused upload, so a rejected file never
     *                        exists on disk even briefly.
     *   write the file       then verify it is there. `PlatformUploadPolicy`'s docblock records *"a
     *                        failed write was reported as success"* as one of the four defects it
     *                        was written to close; `putFileAs()` returns false on failure and the
     *                        first version of a dozen callers in this codebase ignored it.
     *   then the row         inside a transaction with the supersede, so a store cannot end up with
     *                        two live documents of one kind.
     *   then the log         after the row exists, because the row is the subject the log names.
     *
     * IF THE ROW FAILS, THE FILE IS REMOVED. Otherwise an orphan with no row would sit on disk with
     * no access log and nothing pointing at it — the one shape the no-delete rule above must not
     * produce. That is the single place this class removes a file, and it removes only the file it
     * has just written itself, named by a value it generated.
     *
     * @throws KycRefusal
     */
    public static function store(
        KycVerification $profile,
        UploadedFile $file,
        string $kind,
        ?User $actor = null,
        array $attributes = []
    ): KycDocument {
        self::assertPrivateRoot();

        $refusal = self::refusalFor($file, $profile, $kind);

        if ($refusal !== null) {
            throw $refusal;
        }

        $disk = self::disk();
        $directory = (int) $profile->store_id . '/' . (string) $profile->authority;

        /*
         * THE NAME IS THE SECRET. 32 bytes from the CSPRNG, hex-encoded, no extension, nothing of
         * the document or the merchant in it. `bin2hex` and not base64: a stored value passes
         * through middleware that rewrites request strings (XSS::handle), and F205 measured a token
         * containing `&lt;` arriving mangled with no way for the holder to discover why. Hex cannot.
         */
        $name = bin2hex(random_bytes(32));

        $sha256 = (string) hash_file('sha256', $file->getRealPath());

        /*
         * BOTH FAILURE SHAPES, BECAUSE WHICH ONE ARRIVES IS CONFIGURATION. The `kyc` disk sets
         * `'throw' => false`, so Flysystem reports a failed write by returning false — but `throw`
         * is a config key a deployment can change, and `Storage::fake()` REPLACES the disk's whole
         * config (measured: it dropped `throw => false` and the same failure arrived as
         * `UnableToCreateDirectory` instead). A door that handled one shape and not the other would
         * answer a 500 to a full disk in one environment and a refusal in another.
         */
        try {
            $written = Storage::disk($disk)->putFileAs($directory, $file, $name);
        } catch (\Throwable $e) {
            throw new KycRefusal(
                'write_failed',
                (string) __('The document could not be stored. Please try again.')
            );
        }

        if ($written === false || ! Storage::disk($disk)->exists($directory . '/' . $name)) {
            throw new KycRefusal(
                'write_failed',
                (string) __('The document could not be stored. Please try again.')
            );
        }

        try {
            $document = DB::transaction(function () use ($profile, $file, $kind, $disk, $directory, $name, $sha256, $actor, $attributes): KycDocument {
                self::supersedePrevious($profile, $kind, $actor);

                $document = new KycDocument();

                // NOT mass-assigned: these identify the file and the tenant. See KycDocument's $fillable.
                $document->kyc_verification_id = $profile->getKey();
                $document->store_id = (int) $profile->store_id;
                $document->authority = (string) $profile->authority;
                $document->disk = $disk;
                $document->storage_name = $directory . '/' . $name;
                /*
                 * `getMimeType()`, NOT `getClientMimeType()`, AND THE FIRST VERSION HAD THE WRONG
                 * ONE. `getClientMimeType()` is the BROWSER'S CLAIM — attacker-controlled, and the
                 * validator never looked at it: `mimes:` reads the file's own signature. So the
                 * column would have held a stranger's string, and the download door was handing it
                 * straight back as `Content-Type`. Found by reviewing this change against
                 * `.claude/agents/tenant-authz-reviewer.md`'s shape 6 ("obscurity and
                 * caller-supplied values are not controls"), not by a failing test.
                 *
                 * `getMimeType()` guesses from the bytes on disk, which is the same thing `mimes:`
                 * judged. The download door does not trust even this one — it maps the extension to
                 * a fixed type from a whitelist — so this column is a record of what was received
                 * rather than an instruction to a browser.
                 */
                $document->mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
                $document->size_bytes = (int) $file->getSize();
                $document->sha256 = $sha256;
                $document->status = KycDocument::PENDING;

                $document->document_kind = $kind;

                /*
                 * The client's own file name, kept for the reviewer and the merchant's list and
                 * NEVER used to build a path. Cut to the column's width because a name longer than
                 * 191 characters is not a reason to fail an upload, and truncated with `mb_substr`
                 * so an Arabic name is not cut mid-character.
                 */
                $document->original_name = mb_substr((string) $file->getClientOriginalName(), 0, 191);

                $document->issued_on = $attributes['issued_on'] ?? null;
                $document->expires_on = $attributes['expires_on'] ?? null;
                $document->uploaded_by = $actor?->id;

                $document->save();

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($directory . '/' . $name);

            throw $e;
        }

        KycAccessLog::record(KycAccessLog::UPLOADED, $document, [
            'size_bytes' => (int) $document->size_bytes,
            'sha256' => (string) $document->sha256,
        ], $actor);

        return $document;
    }

    /**
     * READ THE BYTES, AND RECORD THE READ. The only reader.
     *
     * CONFINEMENT IS CHECKED EVEN THOUGH THE NAME IS SERVER-GENERATED. The value in the row was
     * generated here, so there is nothing for a traversal to come from today — but the row is in a
     * database, the database has other writers over a system's life, and `StoredUpload`'s whole
     * docblock is the story of a stored path being trusted because of where it came from. The check
     * costs one `realpath()`.
     *
     * A MISSING FILE IS A LOGGED REFUSAL, NOT A SILENT EMPTY. A document whose bytes have gone is
     * exactly the event an access trail needs to show.
     *
     * @throws KycRefusal
     */
    public static function readForActor(KycDocument $document, mixed $actor = null): string
    {
        self::assertPrivateRoot();

        $actingUser = $actor instanceof User ? $actor : null;
        $disk = (string) $document->disk;
        $relative = (string) $document->storage_name;

        if ($disk !== self::disk()) {
            self::refuse($document, 'remote_disk', $actingUser);

            throw new KycRefusal('remote_disk', (string) __('This document cannot be opened.'));
        }

        /*
         * THE NAME MUST BE THE SHAPE THIS CLASS WRITES: `<store>/<authority>/<64 hex>`.
         *
         * Every value in the column was generated here, so nothing today can be another shape — and
         * that is exactly the reasoning `StoredUpload`'s docblock is a monument to: a stored path
         * was trusted BECAUSE OF WHERE IT CAME FROM, and the settings writers turned out to let a
         * tenant choose it. Two things make this worth four lines. A merchant CAN write
         * `storage_setting` through the generic settings writers (no allow-list, no permission —
         * measured in `WebSettingsSecretsTest`), so a tenant can point the platform's own uploader
         * at ANY configured disk, this one included, and place files of their choosing inside this
         * tree. And a row is a row: the day a second writer exists, a path it invents is refused
         * here rather than read.
         *
         * So a planted FILE is unreachable through this door (no row names it) and a planted ROW
         * would have to name the one shape this class writes, inside the one tree it confines to.
         */
        if (preg_match('#^[0-9]+/[a-z_]+/[0-9a-f]{64}$#', $relative) !== 1) {
            self::refuse($document, 'not_confined', $actingUser);

            throw new KycRefusal('not_confined', (string) __('This document cannot be opened.'));
        }

        $root = self::rootOf($disk);
        $real = realpath(Storage::disk($disk)->path($relative));

        if ($real === false || ! is_file($real) || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            self::refuse($document, $real === false ? 'missing' : 'not_confined', $actingUser);

            throw new KycRefusal('missing', (string) __('This document is no longer available.'));
        }

        $bytes = Storage::disk($disk)->get($relative);

        if ($bytes === null) {
            self::refuse($document, 'missing', $actingUser);

            throw new KycRefusal('missing', (string) __('This document is no longer available.'));
        }

        KycAccessLog::record(KycAccessLog::READ, $document, [
            'size_bytes' => strlen($bytes),
        ], $actingUser);

        return $bytes;
    }

    /**
     * Record that somebody was refused. Called by the doors as well as from here, so a refusal at
     * the gate and a refusal at the file are the same kind of row.
     */
    public static function refuse(KycDocument $document, string $reason, ?User $actor = null): void
    {
        KycAccessLog::record(KycAccessLog::REFUSED, $document, ['reason' => $reason], $actor);
    }

    /**
     * A newer document of one kind replaces the older one — kept, marked, and logged.
     *
     * NOT DELETED, for two reasons that are both about the trail: the access log names the old
     * document and a row with no subject is unreadable, and a reviewer who approved the old
     * document must still be able to see what they approved. `superseded` is a terminal state;
     * nothing reads it as current.
     */
    private static function supersedePrevious(KycVerification $profile, string $kind, ?User $actor): void
    {
        $previous = KycDocument::query()
            ->where('store_id', (int) $profile->store_id)
            ->where('kyc_verification_id', $profile->getKey())
            ->where('document_kind', $kind)
            ->whereIn('status', [KycDocument::PENDING, KycDocument::APPROVED, KycDocument::EXPIRED])
            ->get();

        foreach ($previous as $document) {
            $document->status = KycDocument::SUPERSEDED;
            $document->save();

            KycAccessLog::record(KycAccessLog::SUPERSEDED, $document, [], $actor);
        }
    }

    /**
     * THE DISK'S OWN ROOT, ASKED OF THE DISK — NOT OF THE CONFIGURATION.
     *
     * THE FIRST VERSION READ `config('filesystems.disks.<disk>.root')` AND IT WAS A REAL BUG, caught
     * by `KycAccessLogTest` on the first run: `Storage::fake()` in Laravel 11 swaps the RESOLVED
     * disk instance (`static::set($disk, $fake)`) and leaves the configuration ALONE — measured, the
     * config still read `storage/app/private/kyc` while every byte went to
     * `storage/framework/testing/disks/kyc`. So the write and the confinement check had TWO
     * different ideas of where the file was, and every read of a file that had just been written was
     * refused as "missing".
     *
     * That is not a test artefact. It is the defect `StoredUpload`'s `$confineTo` docblock names in
     * so many words — *"TWO resolutions of one string that can disagree, which is the defect this
     * parameter exists to make unexpressible"* — and the same shape would arrive in production from
     * a `config:cache` captured before a root change, or from any package that swaps a disk. The
     * adapter is the only thing that knows where it writes, so it is the only thing asked.
     *
     * The DRIVER and the URL are still read from configuration, deliberately: they are
     * configuration facts, they are checked before the disk is resolved, and resolving a misconfigured
     * remote disk to ask it a question would build a client for a bucket this container has no
     * credentials for.
     */
    private static function rootOf(string $disk): string
    {
        /*
         * RESOLVING A LOCAL DISK CREATES ITS ROOT. Flysystem's `LocalFilesystemAdapter` constructor
         * calls `ensureRootDirectoryExists()`, so a root that cannot be created throws
         * `UnableToCreateDirectory` from the mere act of ASKING WHERE IT IS — measured, and it
         * arrived out of `assertPrivateRoot()` as an uncaught 500 rather than a refusal. An
         * unusable root is a refusal: the caller gets the same sentence as any other storage
         * failure and the reason word says which.
         */
        try {
            return self::canonicalise(rtrim((string) Storage::disk($disk)->path(''), DIRECTORY_SEPARATOR));
        } catch (\Throwable) {
            throw new KycRefusal(
                'unusable_root',
                (string) __('The document could not be stored. Please try again.')
            );
        }
    }

    /**
     * `realpath()` for a path whose leaf may not exist yet.
     *
     * A disk's root is created on first write, so `realpath()` of the configured value is `false` on
     * a fresh install and a comparison against `false` would admit everything. This resolves the
     * longest EXISTING ancestor — which is where symlinks and `..` actually live — and re-appends
     * the rest, so `storage/app/private/kyc` on a tree that has only `storage/app` still resolves
     * through `storage`'s real location. Returns '' for a path with no existing ancestor at all,
     * which every caller treats as a refusal.
     */
    private static function canonicalise(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '') {
            return '';
        }

        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        $tail = [];
        $head = $path;

        while (true) {
            $parent = dirname($head);

            if ($parent === $head) {
                return '';      // walked to the filesystem root without finding anything real
            }

            array_unshift($tail, basename($head));
            $head = $parent;
            $real = realpath($head);

            if ($real !== false) {
                return $real . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $tail);
            }
        }
    }
}
