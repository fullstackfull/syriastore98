<?php

namespace Tests\Unit\Search;

use App\Models\Product;
use App\Observers\ProductNameIndexObserver;
use App\Support\Search\ProductNameIndex;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE OBSERVER'S COMPLETENESS CLAIM, RE-MEASURED.  `CUST-003` / `GAP-190` / owner decision `J38`.
 * =================================================================================================
 *
 * `App\Observers\ProductNameIndexObserver` is a hook on `Product::saving`, and it covers every
 * writer of `products.name` **only for as long as every writer goes through Eloquent**. A raw
 * `update` on the table, or a query-builder `insert`/`upsert`, fires no model event and would leave
 * the fold stale or absent with nothing to say so. This file is what says so.
 *
 * -------------------------------------------------------------------------------------------------
 * THE CENSUS IS INVERTED: THE SAFE SET IS ENUMERATED, SO AN UNTHOUGHT-OF SPELLING FAILS
 * -------------------------------------------------------------------------------------------------
 *
 * `CLAUDE.md` §3: *"WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS, ENUMERATE THE SAFE SET INSTEAD. A
 * list of dangerous spellings is always short by the one nobody thought of"* — a concatenation
 * census keyed on three shapes reported **one** dynamic raw-SQL site where there were **fifteen**.
 * So this file does not look for `update(`. It extracts **every method invoked** on a products-table
 * query or on the `Product` model statically, and requires each one to be on a list of calls that
 * are reads or that fire model events. Anything else fails, named, with its statement printed.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS READ, AND WHAT IS NOT
 * -------------------------------------------------------------------------------------------------
 *
 * READ: every `.php` file under `app/` and `packages/`, comments stripped, for two roots —
 * a products-table query builder, and a static call on the `Product` model. Statement boundaries
 * are found with a quote-aware scan so a semicolon inside a string literal cannot truncate one.
 *
 * NOT READ, and each is a deliberate limit rather than an oversight:
 *
 *  - **`$variable->update([...])` where the variable holds a `Product`.** That is a MODEL update
 *    and it fires `saving`, so it is safe — but it is also indistinguishable, in source, from a
 *    builder update on an unrelated variable. Nothing is claimed about it.
 *  - **Raw SQL strings** (`DB::statement`, `DB::update`) naming the table. `ResidualTenancyExposureTest`
 *    is the repository's inventory of raw-query entry points and owns that question.
 *  - **Database triggers and anything outside the application.** Out of reach of any PHP census.
 *
 * In all three cases the consequence is bounded and is the whole reason the degradation path exists:
 * `ProductNameIndex::applyNameFilter()` keeps the raw `name LIKE` limb as the authority, so a stale
 * or absent fold costs the new Arabic matches and never an existing result.
 */
final class ProductNameIndexCoverageTest extends TestCase
{
    /**
     * Calls that are reads, or writes that go through Eloquent's model events.
     *
     * `insert`, `insertGetId`, `insertOrIgnore`, `upsert`, `update`, `increment`, `decrement`,
     * `truncate` and `updateOrInsert` are absent ON PURPOSE: on a query builder every one of them
     * bypasses `saving`. They are absent rather than listed as forbidden, which is what makes this
     * a safe-set census.
     */
    private const QUERY_READS = [
        'select', 'selectRaw', 'addSelect', 'distinct',
        'where', 'orWhere', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull',
        'whereBetween', 'whereNotBetween', 'whereRaw', 'whereDate', 'whereMonth', 'whereYear',
        'whereColumn', 'whereExists', 'having', 'havingRaw', 'when', 'unless',
        'join', 'leftJoin', 'rightJoin', 'crossJoin',
        'groupBy', 'orderBy', 'orderByRaw', 'latest', 'oldest', 'inRandomOrder',
        'limit', 'take', 'offset', 'skip', 'forPage',
        'get', 'first', 'firstOrFail', 'find', 'findOrFail', 'findMany', 'value', 'pluck',
        'exists', 'doesntExist', 'whereKey', 'with', 'withCount',
        'count', 'sum', 'avg', 'min', 'max', 'chunk', 'chunkById', 'cursor', 'lazy',
        'toSql', 'toArray', 'toBase', 'lockForUpdate', 'sharedLock',
        'map', 'filter', 'keyBy', 'values', 'all', 'isEmpty', 'isNotEmpty', 'merge', 'unique',
        'groupByRaw', 'paginate', 'simplePaginate',
    ];

    /**
     * Static calls on `Product` that are reads, or writes that fire model events.
     *
     * `create`, `forceCreate`, `updateOrCreate`, `firstOrCreate` and `firstOrNew` ARE here: each
     * ends in `$model->save()`, so `saving` runs. `insert` and `upsert` are not — those two are
     * query-builder passthroughs that write rows no model ever saw.
     */
    private const MODEL_SAFE = [
        'query', 'on', 'withoutGlobalScopes', 'withoutGlobalScope', 'withTrashed',
        'create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'firstOrNew', 'make',
        /*
         * The model's own statics, each read from `app/Models/Product.php`: `slugs()` builds a
         * unique slug, `ProductPrice()` and `productSalesTag()` compute presentation values,
         * `Sub_image()` and `productImageDelete()` work on `product_images`. None writes
         * `products.name`.
         */
        'slugs', 'ProductPrice', 'productSalesTag', 'Sub_image', 'productImageDelete',
        /* Framework and package plumbing: the observer registration itself, and model caching. */
        'observe', 'disableCache', 'withCacheCooldownSeconds', 'flushCache',
    ];

    /** @return list<string> every .php file under app/ and packages/ */
    private function sources(): array
    {
        $files = [];

        foreach (['app', 'packages'] as $root) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                /*
                 * `.blade.php` IS EXCLUDED, and for two reasons. A view is not a writer — no
                 * template in this repository saves a product — and the PHP comment stripper is the
                 * wrong instrument for Blade: `{{-- --}}` is not a PHP comment, and a vendored
                 * template that is entirely one `{{-- --}}` block is stripped to nothing, which the
                 * stripper's own guard refuses (measured on
                 * `packages/workdo/BulkDelete/src/resources/views/pages/button.blade.php`).
                 * `ArabicProductSearchTest` strips Blade separately where it needs to.
                 */
                if ($file->isFile()
                    && $file->getExtension() === 'php'
                    && ! str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = substr($file->getPathname(), strlen(base_path()) + 1);
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * COMMENTS OUT, CODE IN — WITH PHP'S OWN TOKENISER, NOT A REGEX, AND NOT THE SHARED TRAIT.
     *
     * `Tests\\Support\\StripsSourceComments` is the repository's instrument and is used everywhere a
     * test asserts about ONE file. It cannot be used here, and the reason is a measurement rather
     * than a preference: its guard **refuses any file that strips to nothing**, which is right for
     * a single-file absence assertion and wrong for a census over 4,000 files, two of which are
     * legitimately 100% comments —
     * `packages/workdo/BulkDelete/src/resources/views/pages/button.blade.php` and
     * `packages/workdo/BulkDelete/src/routes/api.php`, both of which mention a products query
     * inside a comment and so are read by this census.
     *
     * `token_get_all()` is also strictly more correct than stripping by pattern: it cannot mistake
     * a `//` inside a string literal, or a `#[Attribute]`, for a comment.
     * {@see self::the_comment_stripper_removes_comments_and_keeps_code} is the guard the trait's
     * assertion provides there — a stripper that ate everything would turn every census below into
     * a free pass, which is the failure mode of the instrument rather than of the code.
     */
    private function strip(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    /* Keep the newlines, so a reported statement's shape survives. */
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /** A quote-aware statement extractor: a `;` inside a string literal does not end a statement. */
    private function statementAt(string $source, int $from): string
    {
        $quote = null;
        $len = strlen($source);

        for ($i = $from; $i < $len; $i++) {
            $c = $source[$i];

            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;

                    continue;
                }
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;

                continue;
            }

            if ($c === ';') {
                return substr($source, $from, $i - $from + 1);
            }
        }

        return substr($source, $from);
    }

    /** @return list<array{file: string, statement: string, calls: list<string>}> */
    private function statementsRootedAt(string $needle): array
    {
        $found = [];

        foreach ($this->sources() as $relative) {
            $source = $this->strip((string) file_get_contents(base_path($relative)));
            $offset = 0;

            while (($at = strpos($source, $needle, $offset)) !== false) {
                $offset = $at + 1;
                $statement = $this->statementAt($source, $at);

                preg_match_all('/->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $statement, $chained);
                preg_match_all('/::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $statement, $statics);

                $found[] = [
                    'file' => $relative,
                    'statement' => trim(preg_replace('/\s+/', ' ', $statement) ?? $statement),
                    'calls' => array_values(array_unique(array_merge($chained[1], $statics[1]))),
                ];
            }
        }

        return $found;
    }

    /* ======================================================================== the instrument */

    /**
     * THE STRIPPER IS GUARDED BEFORE IT IS TRUSTED, IN BOTH DIRECTIONS.
     *
     * A stripper that removed everything would make every census below pass vacuously; one that
     * removed nothing would let prose about a products query — including this very file's own
     * docblock, were it ever scanned — satisfy or break a census, which `CLAUDE.md` §3 records
     * happening eight times.
     */
    #[Test]
    public function the_comment_stripper_removes_comments_and_keeps_code(): void
    {
        $fixture = <<<'PHP'
        <?php
        /** DB::table('products')->update(['name' => 'x']); */
        $url = 'http://example.test/#not-a-comment';
        // DB::table('products')->insert([]);
        $kept = DB::table('products')->where('id', 1)->value('name');
        # DB::table('products')->truncate();
        $hash = 'a # inside a string';
        PHP;

        $stripped = $this->strip($fixture);

        foreach (['update(', 'insert(', 'truncate('] as $commented) {
            $this->assertStringNotContainsString($commented, $stripped,
                'the stripper left a commented-out call behind: ' . $commented);
        }

        $this->assertStringContainsString("->where('id', 1)->value('name')", $stripped,
            'the stripper ate real code.');
        $this->assertStringContainsString('#not-a-comment', $stripped,
            'the stripper read a `#` inside a string literal as a comment.');
        $this->assertStringContainsString('a # inside a string', $stripped,
            'the stripper read a `#` inside a string literal as a comment.');
    }

    /* ======================================================================== reachability */

    /**
     * THE REACHABILITY ASSERTION. Without it a rename makes every census below pass vacuously.
     *
     * Observers register as listeners under `eloquent.<event>: <Model>`, so this asks the
     * dispatcher the same question the framework will ask at save time.
     */
    #[Test]
    public function the_observer_is_registered_on_the_product_model(): void
    {
        $this->assertTrue(Event::hasListeners('eloquent.saving: ' . Product::class),
            'nothing listens to Product::saving, so no fold is ever written.');

        /*
         * `Model::observe()` registers each handled event as the STRING `Observer@method`, measured
         * on this container. The other listener on this event is the closure
         * `Product::booted()` registers for `StoredPathPolicy::product()`, which arrives as an
         * object and is why the names are filtered rather than compared as a whole list.
         */
        $raw = Event::getRawListeners()['eloquent.saving: ' . Product::class] ?? [];

        $named = array_values(array_filter($raw, 'is_string'));

        $this->assertContains(ProductNameIndexObserver::class . '@saving', $named,
            'ProductNameIndexObserver is not among Product::saving\'s listeners, so no fold is '
            . 'ever written and every census in this file passes vacuously. Registered: '
            . json_encode(array_map(fn ($l) => is_string($l) ? $l : gettype($l), $raw)));
    }

    /** And the column name the observer writes is the one the search predicate reads. */
    #[Test]
    public function the_writer_and_the_reader_name_the_same_column(): void
    {
        $this->assertSame('name_normalised', ProductNameIndex::COLUMN);
        $this->assertSame('products', ProductNameIndex::TABLE);

        $observer = $this->strip((string) file_get_contents(
            base_path('app/Observers/ProductNameIndexObserver.php')));

        $this->assertStringContainsString('ProductNameIndex::COLUMN', $observer,
            'the observer must name the column through the one constant, not by spelling it, or '
            . 'the writer and the reader can drift.');
    }

    /* ======================================================================== the censuses */

    #[Test]
    public function every_products_table_query_builder_statement_is_a_read(): void
    {
        $problems = [];
        $examined = 0;

        foreach (['table(\'products\')', 'table("products")'] as $root) {
            foreach ($this->statementsRootedAt($root) as $hit) {
                $examined++;

                $unknown = array_values(array_diff($hit['calls'], self::QUERY_READS, ['table', 'raw']));

                if ($unknown !== []) {
                    $problems[] = sprintf('%s calls %s on the products table: %s',
                        $hit['file'], implode(', ', $unknown), $hit['statement']);
                }
            }
        }

        $this->assertGreaterThan(0, $examined,
            'premise: the census found products-table queries at all. Zero would mean the root '
            . 'spelling changed and this test stopped reading anything.');

        $this->assertSame([], $problems, sprintf(
            "A products-table query invokes a call that is not on the read list (%d statements "
            . "examined).\n\nIf it WRITES, `ProductNameIndexObserver` never sees it and "
            . "`products.name_normalised` goes stale for those rows — write through Eloquent "
            . "instead. If it is a READ that is simply new, add it to QUERY_READS in this file.\n\n  %s",
            $examined, implode("\n  ", $problems)
        ));
    }

    /**
     * Files in which a bare `Product::` really means `App\Models\Product`.
     *
     * `Product::` is NOT unambiguous. Measured over the 145 files that contain it: 100 import
     * `App\Models\Product`, one imports `Codexshaper\WooCommerce\Facades\Product` (the
     * WooCommerce facade — a different class with different methods), and the rest resolve the bare
     * name against their OWN namespace, which inside a vendored module is that module's own
     * `Product` model on its own table. `packages/workdo/Marketing/.../StoreController.php` calls
     * `Product::create(['is_enabled' => …])` on exactly such a class — a column `products` does not
     * have. Counting it would be a false positive in a completeness claim, which is worse than a
     * gap because it reads like coverage.
     *
     * @return list<string> relative paths whose bare `Product::` is the model this slice is about
     */
    private function filesWhereProductIsTheModel(): array
    {
        $files = [];

        foreach ($this->sources() as $relative) {
            $source = $this->strip((string) file_get_contents(base_path($relative)));

            /*
             * PLAIN STRING MATCHING, NOT A REGEX, AND THE REASON IS THE BACKSLASH. A PHP namespace
             * separator has to survive a single-quoted PHP string AND a PCRE pattern, and the first
             * draft of this method wrote `App\Models\Product` as a pattern that PCRE refused to
             * compile at all ("unrecognized character follows \"). `str_contains` has one layer of
             * escaping instead of two and cannot be wrong about it.
             */
            if (str_contains($source, 'use App\Models\Product;')
                || str_contains($source, 'use \App\Models\Product;')) {
                $files[] = $relative;

                continue;
            }

            /* Another `...\Product` is imported: the bare name is not this model. */
            if (preg_match('/^\h*use\h+[^;]*Product\h*;/m', $source)) {
                continue;
            }

            /* No import at all: the bare name resolves against the file's own namespace. */
            if (str_contains($source, 'namespace App\Models;')) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * THE STATIC ENTRY POINT ON `App\Models\Product` IS ON A SAFE LIST.
     *
     * This is the inverted census for the Eloquent side: the method named immediately after
     * `Product::` must be a read, a builder starter, or a write that ends in `$model->save()` and
     * therefore fires `saving`. `insert`, `insertGetId`, `insertOrIgnore`, `upsert`,
     * `updateOrInsert` and `truncate` are absent from the list ON PURPOSE — every one of them is a
     * query-builder passthrough that writes rows no model ever saw — so a new one fails here,
     * named, rather than quietly producing rows with no fold.
     */
    #[Test]
    public function every_static_entry_point_on_the_product_model_is_on_the_safe_list(): void
    {
        $eligible = $this->filesWhereProductIsTheModel();

        $this->assertGreaterThan(50, count($eligible),
            'premise: the resolver found the files that really use App\Models\Product. A tiny '
            . 'number here means the import patterns changed and this census reads almost nothing.');

        $problems = [];
        $examined = 0;

        foreach ($eligible as $relative) {
            $source = $this->strip((string) file_get_contents(base_path($relative)));

            /*
             * The lookbehind excludes `ProductImage::`, `$this->Product::` and a FULLY QUALIFIED
             * `Other\Product::`. In a single-quoted PHP string `\\\\` is two characters, which PCRE
             * reads as one escaped backslash — getting this wrong is what made the first draft of
             * `filesWhereProductIsTheModel()` fail to compile at all.
             */
            preg_match_all('/(?<![A-Za-z0-9_$>\\\\])Product\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                $source, $m);

            foreach ($m[1] as $method) {
                $examined++;

                if (! in_array($method, self::MODEL_SAFE, true)
                    && ! in_array($method, self::QUERY_READS, true)) {
                    $problems[] = $relative . ': Product::' . $method . '()';
                }
            }
        }

        $this->assertGreaterThan(0, $examined,
            'premise: some static calls were read. Zero would mean the pattern stopped matching.');

        $this->assertSame([], array_values(array_unique($problems)), sprintf(
            "A static call on App\Models\Product is not on the safe list (%d calls examined "
            . "across %d files).\n\nIf it WRITES rows through the query builder — `insert`, "
            . "`upsert`, `insertOrIgnore`, `updateOrInsert`, `truncate` — then `Product::saving` "
            . "never runs, `App\Observers\ProductNameIndexObserver` never sees the row, and "
            . "`products.name_normalised` is absent or stale for it. Write through Eloquent "
            . "instead. If it is a READ or an event-firing write that is simply new, add it to "
            . "MODEL_SAFE or QUERY_READS in this file.\n\n  %s",
            $examined, count($eligible), implode("\n  ", array_unique($problems))
        ));
    }

    /**
     * AND NO BUILDER `update()` IS ROOTED AT `Product::`.
     *
     * **This one hunts for a dangerous spelling, which `CLAUDE.md` §3 warns is always short by the
     * one nobody thought of — and that is a deliberate choice here rather than an oversight.** The
     * inverted form would have to whitelist every call appearing anywhere in a `Product::`
     * statement; measured, that is 96 statements and a vocabulary of about forty names, most of
     * them on other receivers (`Auth::user()->creatorId()`, `->pluck()->prepend()`). Such a ratchet
     * would fire on unrelated work in six other workstreams' files, which `CLAUDE.md` §6b records
     * as the commonest way an unrelated change turns CI red here. The completeness claim rests on
     * the two INVERTED censuses above; this is a narrow second net over the one shape they cannot
     * see, and the degradation path is the real backstop either way.
     *
     * There are zero today. `Product::where(…)->update([...])` is a BUILDER update and fires no
     * model event; `$product->update([...])` on a variable is a MODEL update and does. A hit here
     * is a lead to read, not a verdict.
     */
    #[Test]
    public function no_builder_update_is_rooted_at_the_product_model(): void
    {
        $problems = [];

        foreach ($this->filesWhereProductIsTheModel() as $relative) {
            $source = $this->strip((string) file_get_contents(base_path($relative)));
            $offset = 0;

            while (($at = strpos($source, 'Product::', $offset)) !== false) {
                $offset = $at + 1;

                if ($at > 0 && preg_match('/[A-Za-z0-9_$>\\\\]/', $source[$at - 1])) {
                    continue;   /* ProductImage::, ProductVariant::, $this->Product:: … */
                }

                $statement = $this->statementAt($source, $at);

                /*
                 * ONLY THE VERBS THAT CAN SET AN ARBITRARY COLUMN. `increment`, `decrement`,
                 * `truncate` and `delete` are NOT here, because the question this census asks is
                 * *"can this change `products.name` without firing `saving`?"* and none of them
                 * can: the first two name one numeric column, the last two remove rows, and a row
                 * that is gone has no stale fold.
                 *
                 * MEASURED, and left alone deliberately: `app/Services/ProductStockService.php`
                 * does `Product::query()->whereKey($id)->increment('product_stock', $n)`, a builder
                 * write that fires no model event. It cannot touch `name`, so it is out of this
                 * slice's scope — but `Product` is `Cachable`, so it is a pre-existing stale-read
                 * lead for whoever owns stock, and it is named here rather than silently filtered.
                 */
                if (preg_match('/->\s*(update|upsert|insert|insertGetId|insertOrIgnore|updateOrInsert)\s*\(/', $statement, $hit)) {
                    $problems[] = $relative . ': ' . $hit[1] . '() in '
                        . trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);
                }
            }
        }

        $this->assertSame([], $problems, sprintf(
            "A statement rooted at App\Models\Product chains a query-builder write.\n\nA "
            . "BUILDER `update()`/`insert()`/`increment()` fires no model event, so "
            . "`ProductNameIndexObserver` never runs and `products.name_normalised` is left stale "
            . "for those rows. A MODEL `\$product->update([...])` is safe and would not be seen by "
            . "this census — if the hit below is one, it has been written in an unusual shape and "
            . "is worth reading either way.\n\n  %s",
            implode("\n  ", $problems)
        ));
    }

    /**
     * AND THE WRITER SET ITSELF, BY FILE — the figure `ProductNameIndexObserver`'s docblock quotes.
     *
     * By FILE and COUNT, never by line: `CLAUDE.md` §3 records that an absolute line citation goes
     * stale on any insertion above it and that only the ones a test covers ever say so. A new file
     * in this set is a new writer of `products.name`, and whoever adds it should read the observer.
     *
     * **THE TOTALS ARE ASSERTED AS WELL, BECAUSE THE PROSE FIGURE WAS WRONG.** The observer's
     * docblock and `AppServiceProvider`'s comment first read *"fifteen sites in EIGHT files"*. The
     * real counts are 14 attribute assignments across 6 files plus 1 mass assignment in a 7th —
     * fifteen writers in **seven** files. Nothing asserted it, which is the same shape as the
     * eleven docblocks that carried a stale model-caching figure (`CLAUDE.md` §1: *"a duplicated
     * figure is only safe if something checks it"*). It is checked now.
     */
    #[Test]
    public function the_set_of_files_that_assign_a_product_name_is_the_declared_one(): void
    {
        $expected = [
            'app/Http/Controllers/Api/DashboardController.php'                                   => 2,
            'app/Http/Controllers/ProductController.php'                                         => 3,
            'app/Http/Controllers/ShopifyProductController.php'                                  => 2,
            'app/Http/Controllers/WoocomProductController.php'                                   => 2,
            'packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionProductController.php' => 3,
            'packages/workdo/DigitalProduct/src/app/Http/Controllers/DigitalProductController.php'  => 2,
        ];

        $actual = [];

        foreach ($this->sources() as $relative) {
            $source = $this->strip((string) file_get_contents(base_path($relative)));

            /*
             * `$Product->name =` / `$product->name =`, on a variable whose name is exactly the
             * model's. Deliberately narrow: `$productBrand->name =` and `$productLabel->name =`
             * are other tables, and a wider pattern would count them.
             */
            preg_match_all('/\$[Pp]roduct\s*->\s*name\s*=[^=]/', $source, $m);

            if ($m[0] !== []) {
                $actual[$relative] = count($m[0]);
            }
        }

        ksort($expected);
        ksort($actual);

        $this->assertSame(14, array_sum($expected),
            'this file\'s own expectation no longer sums to the 14 attribute assignments the '
            . 'observer\'s docblock quotes. Fix both together.');
        $this->assertSame(6, count($expected),
            'this file\'s own expectation no longer names 6 files. With the CSV importer\'s mass '
            . 'assignment, asserted separately, that is 15 writers in SEVEN files.');

        $this->assertSame($expected, $actual, sprintf(
            "The set of files that assign `products.name` has changed.\n\n"
            . "Six of the declared sites are inside `packages/workdo/`, which must not be edited — "
            . "which is why the fold is written by a model observer and not per writer. A new file "
            . "here is covered automatically by `ProductNameIndexObserver` **provided it saves "
            . "through Eloquent**; update this expectation and the observer's docblock census, "
            . "which quotes these figures.\n\nExpected: %s\nActual:   %s",
            json_encode($expected, JSON_PRETTY_PRINT), json_encode($actual, JSON_PRETTY_PRINT)
        ));
    }

    /**
     * THE SEVENTH WRITER IS A MASS ASSIGNMENT AND NO `->name =` PATTERN CAN SEE IT.
     *
     * `packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php` does
     * `new Product(['name' => $row['product_name'] ?? '', …])` — the spreadsheet cell the brief
     * names. It is asserted separately because the census above would silently read zero for it,
     * and a census that cannot see a writer is how a completeness claim goes wrong.
     */
    #[Test]
    public function the_csv_importers_mass_assignment_is_still_the_shape_the_observer_covers(): void
    {
        $source = $this->strip((string) file_get_contents(base_path(
            'packages/workdo/ImportExport/src/app/Imports/ProductsImportData.php')));

        $this->assertStringContainsString('new Product(', $source,
            'the CSV importer no longer constructs a Product. If it now writes rows another way, '
            . 'check that way still fires Product::saving.');

        $this->assertMatchesRegularExpression('/new Product\(\s*\[/', $source,
            'the CSV importer no longer mass-assigns an array, which is the path '
            . 'ProductNameIndexObserverTest::test_a_mass_assigned_name_is_folded_too drives.');
    }
}
