# P01-SEC-2 — route-cache verification

`App\Http\Middleware\OrderDocumentAccessGuard` is registered with
`$middleware->appendToGroup('web', ...)` in `bootstrap/app.php` rather than on the routes it
protects. That choice is forced: nine of its seventeen target uris are declared in vendored module
route files that `.github/scripts/guard-no-workdo-changes.sh` forbids editing, and those files are
required from `$this->app->booted()` (`app/Providers/PackageServiceProvider.php:35`) — i.e. *after*
`routes/web.php` — so a host re-declaration loses the method+uri slot again.

The obvious question about a group-appended middleware is whether it survives
`php artisan route:cache`, which is what a production deploy runs. Reasoning about Laravel's
internals is not evidence, so this was measured.

**The claim tested:** with routes cached, a request to a guarded uri still has
`OrderDocumentAccessGuard` in its resolved middleware stack.

## How to reproduce

    DB_CONNECTION=sqlite DB_DATABASE=/tmp/rc.sqlite \
        php tests/Support/Security/bin/verify-order-document-route-cache.php

`tests/Support` is not one of the three directories `phpunit.xml` collects, so the script never
runs as part of a suite — it cannot be a PHPUnit test, because the claim is about what a *separate*
process does with the cache file an earlier process wrote.

It never touches a real database: `DB_CONNECTION`/`DB_DATABASE` are read from the environment and
Laravel's Dotenv is immutable, so `.env` cannot win them back. Step 1 asserts the override took
effect and aborts before writing anything if it did not. Step 6 clears the route cache and asserts
the file is gone.

## What it found

Two things, and the second is the one that matters.

**The cache stores the declared middleware NAME, not the group's members.** The cache file names
neither `OrderDocumentAccessGuard` nor the pre-existing `BulkDeleteTenantGuard`; each route's
declared middleware is just `web`. The group is resolved per request from `bootstrap/app.php`,
which every request re-executes, so appending to the group reaches routes that were cached before
the append existed.

**A separate process booting from the cache resolves the guard into all four stacks** — including
`shippinglabel/pdf/{id}`, whose action is the host controller while its stack has lost
`Authenticate` to the module's re-declaration.

## Transcript

```
STEP 1 -- the database is a throwaway sqlite file, not a real one.
  [ OK ] config(database.default) === sqlite
         actual: 'sqlite'
  [ OK ] the sqlite file is outside the repository
         actual: '/tmp/claude-0/-home-user/3afaca23-b022-5fdd-8f35-bfb6b44c8051/scratchpad/p01sec2/rc3.sqlite'

STEP 2 -- enable AutomaticOrderPrinting through the production loader's own table.
  [ OK ] add_on_managers holds the row

STEP 3 -- php artisan route:cache (a fresh application boot, in its own process).
         
            INFO  Routes cached successfully.
         
  [ OK ] route:cache exited 0
  [ OK ] bootstrap/cache/routes-v7.php exists

STEP 4 -- WHAT THE CACHE FILE ITSELF CONTAINS.
  [ OK ] the cache does NOT name OrderDocumentAccessGuard
         This is the mechanism: the cache stores declared middleware NAMES ("web") unexpanded, so the group's members are resolved per request from bootstrap/app.php instead of frozen here.
  [ OK ] the cache DOES name the group "web" as a declared middleware
         The declared name is what is frozen; its members are not.
  [ OK ] the cache does NOT name BulkDeleteTenantGuard either
         The pre-existing group-appended guard behaves identically, which is why this placement was chosen for the new one.

STEP 5 -- A SEPARATE PROCESS, booting FROM the cache, resolves the stack.
  [ OK ] the probe process booted FROM the route cache

         GET shippinglabel/pdf/{id}
           action   App\Http\Controllers\OrderController@shippinglabel
           declared [web]
           resolved [EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings, SetLocate, BulkDeleteTenantGuard, OrderDocumentAccessGuard, InjectDebugbar]
  [ OK ]    shippinglabel/pdf/{id} carries the guard under route:cache

         GET shippinglabel/{id?}/{template?}/{color?}
           action   Workdo\AutomaticOrderPrinting\app\Http\Controllers\AutomaticOrderPrintingController@shippinglabel
           declared [web]
           resolved [EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings, SetLocate, BulkDeleteTenantGuard, OrderDocumentAccessGuard, InjectDebugbar]
  [ OK ]    shippinglabel/{id?}/{template?}/{color?} carries the guard under route:cache

         GET printlabel/{id?}/{template?}/{color?}
           action   Workdo\AutomaticOrderPrinting\app\Http\Controllers\AutomaticOrderPrintingController@printinglabel
           declared [web]
           resolved [EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings, SetLocate, BulkDeleteTenantGuard, OrderDocumentAccessGuard, InjectDebugbar]
  [ OK ]    printlabel/{id?}/{template?}/{color?} carries the guard under route:cache

         GET invoices/preview/{template}/{color}
           action   Workdo\AutomaticOrderPrinting\app\Http\Controllers\AutomaticOrderPrintingController@invoice_preview
           declared [web]
           resolved [EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings, SetLocate, BulkDeleteTenantGuard, OrderDocumentAccessGuard, InjectDebugbar]
  [ OK ]    invoices/preview/{template}/{color} carries the guard under route:cache
  [ OK ] all four target routes were present in the cached collection
         found: 4

STEP 6 -- clean up, so nothing is left behind.
  [ OK ] route:clear exited 0
  [ OK ] bootstrap/cache/routes-v7.php is gone

RESULT: ALL CHECKS PASSED
```

## What this does NOT establish

Whether enabling a module *after* a route cache was built changes the live route table. An attempt
to measure that could not distinguish "the stale cache wins" from "the module failed to load in a
sqlite environment holding only `add_on_managers`", so no claim is made in either direction. It
does not affect the guard: the guard is on the group, so it applies to whichever of the two
declarations of `shippinglabel/pdf/{id}` is live.

The script also covers only the four `AutomaticOrderPrinting` uris, not all seventeen targets. The
other nine are pinned instead by the census ratchet in
`tests/Feature/Security/OrderDocumentRouteResolutionTest`, which reads the route *files* and so
needs no module enabled; the mechanism proven here is a property of the `web` group, not of any
particular route.
