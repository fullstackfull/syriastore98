<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToStore;
use App\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE REGRESSION GUARD FOR THE StoreScope CLASS OF DEFECT.
 *
 * ==========================================================================================
 *  WHY THIS CLASS EXISTS, stated plainly because the lesson cost a whole review round.
 *
 *  App\Scopes\StoreScope was committed implementing `Illuminate\Database\Eloquent\Scopes\Scope`
 *  -- an interface that DOES NOT EXIST in Laravel 11. The real one is
 *  `Illuminate\Database\Eloquent\Scope`; there is no `Scopes/` directory in the framework at
 *  all. Because `BelongsToStore::bootBelongsToStore()` does `new StoreScope()`, EVERY model
 *  carrying the trait fatalled with "Interface not found" the first time it was instantiated.
 *
 *  `php -l` passed the file. It always will: a missing interface is a runtime autoload failure,
 *  not a parse error. Syntax linting cannot see it, and no amount of reading the diff reliably
 *  catches a plausible-looking namespace.
 *
 *  THE ONLY THING THAT CATCHES IT IS CONSTRUCTING THE OBJECT. So this class constructs every
 *  model that carries the trait, boots it, and runs a real query through it.
 * ==========================================================================================
 *
 * The model list is DISCOVERED FROM THE FILESYSTEM, never hand-maintained. A hand-written list
 * would not cover a model that someone adds the trait to tomorrow, which is precisely the
 * regression this class is supposed to prevent.
 */
class ModelInitializationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every class in app/Models that carries the BelongsToStore trait.
     *
     * Discovered by reading the directory rather than by listing names, so a model that gains
     * the trait later is covered automatically.
     *
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function belongsToStoreModels(): array
    {
        $cases = [];

        // NOT app_path(): a static data provider runs BEFORE the Laravel application is
        // booted, so the container has no path helpers yet and app_path() throws
        // "Call to undefined method Illuminate\Container\Container::path()".
        foreach (glob(dirname(__DIR__, 3) . '/app/Models/*.php') as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! in_array(BelongsToStore::class, class_uses_recursive($class), true)) {
                continue;
            }

            $cases[basename($file, '.php')] = [$class];
        }

        ksort($cases);

        return $cases;
    }

    /**
     * The guard on the discovery itself. If the glob silently matched nothing -- a renamed
     * directory, a changed namespace -- every data-provider test below would vacuously pass and
     * this class would protect nothing at all.
     *
     * 11 is the count at the time of writing. The assertion is a lower bound, not an equality,
     * so adding a model to the trait does not fail the suite.
     */
    public function test_the_trait_carrying_model_set_was_actually_discovered(): void
    {
        $discovered = self::belongsToStoreModels();

        $this->assertGreaterThanOrEqual(
            11,
            count($discovered),
            'Discovery found fewer BelongsToStore models than exist. Every per-model test in this '
            . 'class is data-provider driven, so a broken discovery makes them all vacuous. Fix '
            . 'the discovery before trusting any green result here.'
        );
    }

    /**
     * THE CORE ASSERTION. Construct the model. This is the single step that `php -l` cannot
     * perform and that the StoreScope defect required.
     *
     */
    #[DataProvider('belongsToStoreModels')]
    public function test_model_can_be_instantiated(string $modelClass): void
    {
        $model = new $modelClass();

        $this->assertInstanceOf(Model::class, $model);
    }

    /**
     * Instantiating is necessary but not sufficient: Eloquent boots a model's traits lazily, so
     * the scope object itself must be built and must satisfy the framework's contract.
     *
     * This asserts the scope is REGISTERED and that it is a real
     * `Illuminate\Database\Eloquent\Scope`. The second half is the direct regression assertion
     * for the original defect: a class implementing a non-existent interface cannot satisfy
     * this, and a class implementing the wrong-but-existing interface would not either.
     *
     */
    #[DataProvider('belongsToStoreModels')]
    public function test_store_scope_is_registered_and_is_a_real_eloquent_scope(string $modelClass): void
    {
        $model = new $modelClass();

        $scopes = $model->getGlobalScopes();

        $this->assertArrayHasKey(
            StoreScope::class,
            $scopes,
            $modelClass . ' carries BelongsToStore but has no StoreScope registered, so the trait '
            . 'is not booting. Tenancy machinery on this model is inert.'
        );

        $this->assertInstanceOf(
            Scope::class,
            $scopes[StoreScope::class],
            'StoreScope is not an Illuminate\\Database\\Eloquent\\Scope. This is the exact defect '
            . 'this class exists to catch: the scope was once written against '
            . 'Illuminate\\Database\\Eloquent\\Scopes\\Scope, which does not exist in Laravel 11, '
            . 'and php -l passed it while every model fatalled on instantiation.'
        );
    }

    /**
     * Build a real query through the model. `apply()` runs here, so a scope that constructs but
     * throws when applied -- a bad `qualifyColumn()`, a missing method -- is caught.
     *
     * Deliberately runs with the scope DISABLED, which is production's default, and asserts the
     * behaviour-neutrality claim BelongsToStore makes: while disabled the trait must add no
     * predicate, because 78 models' cache keys derive from the emitted SQL.
     *
     */
    #[DataProvider('belongsToStoreModels')]
    public function test_query_builds_and_adds_no_predicate_while_the_scope_is_disabled(string $modelClass): void
    {
        StoreScope::$enabled = false;

        $model = new $modelClass();
        $sql = $modelClass::query()->toSql();

        $this->assertIsString($sql);
        $this->assertStringNotContainsString(
            $model->qualifyColumn($model->getStoreColumn()),
            $sql,
            'The disabled scope added a tenant predicate to ' . $modelClass . '. Attaching the '
            . 'trait is supposed to be behaviour-neutral until GATE G2 clears, and every '
            . 'laravel-model-caching key is derived from this SQL.'
        );
    }

    /**
     * Execute the query against the database, not merely compile it. A model whose table does
     * not exist is SKIPPED and named, because three trait-carrying models (StoreWallet,
     * StoreWalletTransaction, StoreWithdrawal) are GATE F money tables with no migration --
     * reporting that honestly is the point, and a silent pass would hide it.
     *
     */
    #[DataProvider('belongsToStoreModels')]
    public function test_query_actually_executes(string $modelClass): void
    {
        $model = new $modelClass();

        if (! Schema::hasTable($model->getTable())) {
            $this->markTestSkipped(
                'Table `' . $model->getTable() . '` has no migration (GATE F / SCHEMA-01), so the '
                . 'query cannot be executed. The class instantiates and its scope is registered; '
                . 'only execution is unverified.'
            );
        }

        // count() forces compilation AND execution through every global scope on the model.
        $this->assertIsInt($modelClass::count());
    }

    /**
     * The trait's own surface must exist on each model. These are the methods the controller
     * fixes call, so a missing one is a silent authorization gap rather than a visible error.
     *
     */
    #[DataProvider('belongsToStoreModels')]
    public function test_trait_surface_is_present(string $modelClass): void
    {
        $model = new $modelClass();

        foreach (['getStoreColumn', 'isOwnedByStore', 'isOwnedByCurrentStore'] as $method) {
            $this->assertTrue(
                method_exists($model, $method),
                $modelClass . ' is missing ' . $method . '(), which the R4-04 call sites use.'
            );
        }

        $this->assertIsString($model->getStoreColumn());

        // The named scopes resolve through Eloquent's scope dispatch, not method_exists.
        $this->assertInstanceOf(Builder::class, $modelClass::query()->forStore(1));
        $this->assertInstanceOf(Builder::class, $modelClass::query()->withoutStoreScope());
    }
}
