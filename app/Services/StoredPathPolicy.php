<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Which strings may be stored in a column that LATER becomes a filesystem path.  P1-SEC-28.
 *
 * The host's own deleters go through App\Services\StoredUpload, which refuses a path outside uploads/ at the moment of the unlink. The
 * VENDORED module deleters (AuctionProduct, DigitalProduct, SignInWithLinkedIn, SignInWithFacebook) cannot be edited and do
 * `File::delete(base_path($row->cover_image_path))` on whatever the row holds -- and the ImportExport CSV importer writes `cover_image`,
 * `preview_content` and `downloadable_product` from a spreadsheet cell, raw. So `.env` or `config/*` in a cell, then a delete through a
 * vendored handler, removed it. The control that reaches those readers is on the WRITE side: a model rule that keeps the value a
 * relative path under one of the roots uploads actually live in. Only a value that CHANGES is judged, so a legacy row is never made
 * unsaveable by an unrelated edit.
 *
 * Accepted: empty; an absolute http(s) URL (a cover image kept on another host -- `base_path('https://x/..')` names a directory `https:` that
 * does not exist, so no deleter can reach a file through it); or a relative path under `uploads/` (also spelled `public/uploads/` and
 * `storage/uploads/`) with no `..`/`.`/empty segment and no control byte or backslash; or exactly ImportExport's bundled default image
 * (a shared vendored asset: the host deleters refuse it because it is outside uploads/; a vendored deleter could still remove it --
 * recorded in the ledger). When the OWNING STORE is known the first segment under `uploads/` (peeling the gallery's doubled `uploads/`)
 * must be that store's folder, or the folder of another store the same owner runs: a poisoned row cannot name another tenant's file or one of
 * the platform's shared ones (`uploads/profile/..`, `uploads/logo/..`) for a vendored deleter to remove.
 *
 * REVIEW FINDINGS folded in (a read-only review of c71c717e): the first version also accepted `public/`, `storage/` and `themes/` as roots,
 * so a vendored deleter could still be pointed at `public/index.php` or `storage/logs/laravel.log`; it accepted any tenant's folder; it
 * refused `* ? [ ] { } < > | "` -- characters real upload names contain (`photo[1].jpg`), so a product save failed AFTER its file was
 * written and its quota debited (nothing here globs: File::delete is unlink); and it refused the absolute URLs a CSV import carries.
 */
final class StoredPathPolicy
{
    public const IMPORT_EXPORT_DEFAULT = 'packages/workdo/ImportExport/src/resources/assets/img/img.png';

    private const ROOTS = ['uploads', 'public/uploads', 'storage/uploads'];

    /** @param int|string|null $storeId the store that owns the row, when the caller knows it */
    public static function isSafe($path, $storeId = null): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        if (! is_string($path)) {
            return false;
        }

        if ($path === self::IMPORT_EXPORT_DEFAULT) {
            return true;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
            return false;
        }

        if (preg_match('#^https?://[^\s/]#i', $path) === 1) {
            return true;
        }

        // a leading slash, a drive letter or any other scheme all make the FIRST segment something other than one of the roots below
        $segments = explode('/', $path);

        if (array_intersect(['..', '.', ''], $segments) !== []) {
            return false;
        }

        foreach (self::ROOTS as $root) {
            $rootSegments = explode('/', $root);

            if (array_slice($segments, 0, count($rootSegments)) === $rootSegments && count($segments) > count($rootSegments)) {
                return $storeId === null || self::inStoreFolder(array_slice($segments, count($rootSegments)), $storeId);
            }
        }

        return false;
    }

    /** @param list<string> $segments what follows the root; the gallery helper prepends `uploads/` a second time */
    private static function inStoreFolder(array $segments, $storeId): bool
    {
        while (($segments[0] ?? null) === 'uploads' && count($segments) > 1) {
            array_shift($segments);
        }

        $folder = $segments[0] ?? '';

        // a store's folder is its id in its canonical spelling: `007` is a folder no store owns, not store 7's (the owner lookup below casts it)
        if (! ctype_digit($folder) || $folder !== (string) (int) $folder || count($segments) < 2) {
            return false;
        }

        return self::sameTenantStores((int) $folder, $storeId);
    }

    /**
     * Is store $a the store $b, or another store the same owner runs? A store that is not a store (null, 0 or less) is nobody's: a folder that
     * happens to be called `0` belongs to no store, and an ownerless store is only itself.  P1-SEC-70.
     *
     * @param int|string|null $a
     * @param int|string|null $b
     */
    public static function sameTenantStores($a, $b): bool
    {
        if ($a === null || $b === null || (int) $a <= 0 || (int) $b <= 0) {
            return false;
        }

        if ((int) $a === (int) $b) {
            return true;
        }

        $owner = \App\Models\Store::query()->whereKey((int) $b)->value('created_by');

        return $owner !== null && (string) \App\Models\Store::query()->whereKey((int) $a)->value('created_by') === (string) $owner;
    }

    /**
     * @throws ValidationException
     */
    public static function assert(string $attribute, $value, $storeId = null): void
    {
        if (! self::isSafe($value, $storeId)) {
            throw ValidationException::withMessages([$attribute => [__('The file path is not accepted.')]]);
        }
    }

    /** Product::saving -- every path column a vendored deleter reads, judged only when it changes. */
    public static function product(\App\Models\Product $product): void
    {
        foreach (['cover_image_path', 'downloadable_product'] as $attribute) {
            if (! $product->exists || $product->isDirty($attribute)) {
                self::assert($attribute, $product->getAttribute($attribute), $product->store_id);
            }
        }

        // `preview_content` is a path only for a video FILE (a video URL lives in the same column)
        if ($product->getAttribute('preview_type') === 'Video File' && (! $product->exists || $product->isDirty(['preview_content', 'preview_type']))) {
            self::assert('preview_content', $product->getAttribute('preview_content'), $product->store_id);
        }

        if (! $product->exists || $product->isDirty('other_description_api')) {
            $decoded = json_decode((string) $product->getAttribute('other_description_api'), true);

            foreach ((array) ($decoded['product-other-description'] ?? []) as $field) {
                if (is_array($field) && ($field['field_type'] ?? null) === 'photo upload') {
                    self::assert('other_description_api', $field['value'] ?? null, $product->store_id);
                }
            }
        }
    }

    /** ProductImage::saving */
    public static function productImage(\App\Models\ProductImage $image): void
    {
        if (! $image->exists || $image->isDirty('image_path')) {
            self::assert('image_path', $image->getAttribute('image_path'), \App\Models\Product::withoutGlobalScopes()->whereKey($image->product_id)->value('store_id'));
        }
    }

    /**
     * The rows the vendored sign-in handlers read, BY NAME (`where name = '<this>'`). SignInImageDeleteGuard::HANDLERS names the same two and a test pins that
     * they agree: a third module of the same shape has to be added to both.
     */
    public const SIGN_IN_ROWS = ['facebook_sign_in_image', 'linkedin_sign_in_image'];

    /** @var array<string, array{collation: ?string, length: int}> what `settings.name` really is, per connection and database, read once per process */
    private static array $nameColumns = [];

    /** @var array{collation: ?string, length: int}|null a test's stand-in for the live column (useNameColumn) */
    private static ?array $nameColumnOverride = null;

    /**
     * What `settings.name` REALLY is: its collation and its width, from the engine.  P1-SEC-78 (review of the first form).
     *
     * The vendored lookups compare `where name = ?` under the COLUMN's collation (a column outranks a parameter in the engine's coercion rules), and the column keeps a name
     * cut to ITS width. Neither is the connection's, and the migrations are not the schema: the measured production dump is MariaDB 11.4.9 (GateFProductionSchema), where
     * `audit_logs` is utf8mb4_general_ci while the application default is utf8mb4_unicode_ci. A question asked under the connection's collation ("is `facebook_ßign_in_image`
     * the sign-in name?": no under unicode_ci, where ß is `ss`; yes under general_ci, where it is `s`) can be answered differently from the lookup. So it is asked under the
     * column's own. Where there is no `information_schema` (SQLite, in the tests), where the column is not utf8mb4, or where the read fails, the answer is the connection's
     * collation and the migration's 255 -- what this rule did before -- and `collation` is null.
     *
     * @return array{collation: ?string, length: int}
     */
    public static function nameColumn(): array
    {
        if (self::$nameColumnOverride !== null) {
            return self::$nameColumnOverride;
        }

        $connection = (new \App\Models\Setting())->getConnection();
        $key = $connection->getName() . '|' . $connection->getDatabaseName();

        if (isset(self::$nameColumns[$key])) {
            return self::$nameColumns[$key];
        }

        $column = ['collation' => null, 'length' => HostClaims::NAME_LENGTH];

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                $row = $connection->selectOne(
                    'select CHARACTER_SET_NAME as cs, COLLATION_NAME as co, CHARACTER_MAXIMUM_LENGTH as len from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?',
                    [$connection->getDatabaseName(), $connection->getTablePrefix() . 'settings', 'name']
                );
            } catch (\Throwable $e) {
                $row = null;
            }

            if ($row !== null) {
                if (($row->cs ?? null) === 'utf8mb4' && preg_match('/\Autf8mb4_[a-z0-9_]+\z/', (string) ($row->co ?? '')) === 1) {
                    $column['collation'] = (string) $row->co;
                }

                if ((int) ($row->len ?? 0) > 0) {
                    $column['length'] = (int) $row->len;
                }
            }
        }

        return self::$nameColumns[$key] = $column;
    }

    /** FOR TESTS: stand in for the live column (SQLite has none). `null` puts the lookup back and forgets what was read. */
    public static function useNameColumn(?array $column): void
    {
        self::$nameColumnOverride = $column;
        self::$nameColumns = [];
    }

    /**
     * The question put to the engine for a name that is not plain: is `lower(trim(name))` one of SIGN_IN_ROWS, under THIS collation?
     *
     * `collate` goes on the OUTSIDE of the function and on each parameter, because that is the spelling every engine reads the same way (MySQL and MariaDB carry the collation
     * through LOWER and TRIM; SQLite does not, and takes the left operand's). The collation is a name read from information_schema and checked again here: it is the one part of
     * the statement that is not a bound value.
     *
     * @return array{0: string, 1: list<string>} the SQL and its bindings
     */
    public static function engineQuestion(string $stored, ?string $collation): array
    {
        $marks = implode(', ', array_fill(0, count(self::SIGN_IN_ROWS), '?'));

        if ($collation === null) {
            return ['select lower(trim(?)) in (' . $marks . ') as hit', [$stored, ...self::SIGN_IN_ROWS]];
        }

        if (preg_match('/\Autf8mb4_[a-z0-9_]+\z/', $collation) !== 1) {
            throw new \InvalidArgumentException('That is not a utf8mb4 collation name.');
        }

        $by = ' collate ' . $collation;

        return ['select lower(trim(?))' . $by . ' in (' . implode(', ', array_fill(0, count(self::SIGN_IN_ROWS), '?' . $by)) . ') as hit', [$stored, ...self::SIGN_IN_ROWS]];
    }

    /**
     * Is this the name of a row a vendored sign-in handler would FIND?  P1-SEC-78.
     *
     * The rule in setting() decided that by `preg_match('/_sign_in_image$/', strtolower(trim($name)))` on the name AS POSTED. The handlers' lookups -- and the
     * table -- know the name as the ENGINE does, and the engine is not PHP, in two ways:
     *
     *   - a `_ci` collation is accent-, case- and PAD-SPACE-insensitive, and the UCA ones ignore some code points, so `facebook_sign_in_imagé`, a trailing U+200B, a trailing
     *     no-break space and a full-width `ｉ` all FIND the row of `facebook_sign_in_image` under utf8mb4_unicode_ci (EXECUTED by the skeptics of P1-SEC-70 on MariaDB 10.11; the
     *     repository's own S46 note records the same fold from MySQL 8.0.46);
     *   - `settings.name` is a VARCHAR and production runs `strict => false`, so a longer name is STORED CUT to the column's width, the spaces that remain are PAD SPACE, and a
     *     MySQL 8.0 column also ends a name at an INVALID UTF-8 byte (`domains\xFFx` is stored as `domains`: EXECUTED on 8.0.46 by verify-host-claims-on-mysql.php).
     *
     * So the name is judged as it will be STORED (HostClaims::asStored, at the column's real width), PHP-visible spellings (case, surrounding spaces and the control bytes
     * trim() strips) are judged by the pattern as before, and a name that is not plain printable ASCII is PUT TO THE ENGINE, the way HostClaims does it for the host settings --
     * but under the COLUMN's collation, not the connection's (nameColumn). It is a sign-in name when `lower(trim(name))` equals one of SIGN_IN_ROWS there. A plain name costs
     * no query. A non-plain name that folds onto nothing here -- `thème_color` -- is nobody's business and is saved exactly as before (HostClaimsTest pins that, and this change
     * must not reverse a decision that review made). **An engine that gives no answer has not said no:** a NULL is read as "yes" and the value is judged.
     */
    public static function isSignInName(string $name): bool
    {
        $column = self::nameColumn();
        $stored = HostClaims::asStored($name, $column['length']);

        if (preg_match('/_sign_in_image$/', strtolower(trim($stored))) === 1) {
            return true;
        }

        if (HostClaims::plain($stored)) {
            return false;
        }

        [$sql, $bindings] = self::engineQuestion($stored, $column['collation']);
        $row = (new \App\Models\Setting())->getConnection()->selectOne($sql, $bindings);

        return ($row->hit ?? 1) != 0;
    }

    /**
     * Setting::saving -- the names the vendored sign-in modules unlink (`linkedin_sign_in_image`, `facebook_sign_in_image`, ...): the value of such a row is held
     * to the path rule, whatever spelling of the name reached the table (isSignInName). Only when the row is NEW or its name or value CHANGES, so a legacy row is
     * never made unsaveable by an unrelated edit -- and the cheap question goes first, so the engine is not asked about a save that changes neither.
     */
    public static function setting(\App\Models\Setting $setting): void
    {
        if ($setting->exists && ! $setting->isDirty(['name', 'value'])) {
            return;
        }

        if (self::isSignInName((string) $setting->name)) {
            self::assert('value', $setting->value);
        }
    }
}
