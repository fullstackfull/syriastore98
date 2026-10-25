<?php

namespace App\Classes;

class Menu
{
    public $menu = [];
    public $user;
    public $modules;

    /**
     * Names this gate REFUSED, keyed by `name`.
     *
     * P2-SEC-F4. The gate below is the only module-entitlement and permission check the
     * navigation has, and it is reachable only by callers that go through `add()`. The
     * customised sidebar (`getSideMenu()` with `SidebarCustomization` active) does not: it
     * reads `side_menus_option` rows and pushes them onto `$menu` directly. Those rows cannot
     * carry their own entitlement either, because the table HAS NO `module` AND NO `permission`
     * COLUMN. Three independent readings agree:
     *
     *   1. the module's own migration,
     *      packages/workdo/SidebarCustomization/src/database/migrations/2024_11_26_050003_create_side_menus_option_table.php,
     *      declares seventeen columns and neither of those two;
     *   2. `SuperadminMenuTableSeeder` and `AdminMenuTableSeeder` DO carry `module` and
     *      `permission` in their PHP arrays and then DROP BOTH on insert -- their
     *      `DB::table('side_menus_option')->updateOrInsert()` lists sixteen columns and neither
     *      of these is among them;
     *   3. the restored production schema for this table matches the migration exactly. (That
     *      reading was taken from the database dump committed to `main`, which is the P1-SEC-40
     *      / #120 incident artifact and is deliberately NOT on this branch; it is cited as
     *      corroboration only, and 1 and 2 are each sufficient on their own.)
     *
     * So
     * `$item->module ?? null` and `$item->permission ?? null` in `getSideMenu()` were
     * structurally ALWAYS NULL, and there was no column to build a gate from.
     *
     * Recording the refusals here is what gives that path a gate without a schema change: the
     * listeners are the authoritative `name => [module, permission]` map, this class already
     * applies the decision, and a database row naming one of those entries can now honour the
     * same answer. One rule, evaluated once, read twice -- rather than a second spelling of it
     * next to the renderer.
     *
     * @var array<string, true>
     */
    public array $denied = [];

    public function __construct($user)
    {
        $this->user = $user;
        $this->modules = ActivatedModule();
        $this->modules[] =  'Base';
    }

    public function add(array $array): void {
        if ($this->permits($array)) {
            $this->menu[] = $array;

            return;
        }

        if (! empty($array['name'])) {
            $this->denied[$array['name']] = true;
        }
    }

    /**
     * THE gate, extracted verbatim so `add()`'s behaviour is unchanged and a second menu source
     * can ask the same question instead of re-implementing it.
     *
     * `$array['module'] ?? null` where the original indexed `$array['module']` directly: every
     * listener in the repository sets the key, so this is the identical answer for every caller
     * that exists, and a defensive read for one that does not. `in_array(null, $this->modules)`
     * is false for any non-empty module name, so a row with no module is refused rather than
     * waved through -- which is the safe direction.
     */
    public function permits(array $array): bool
    {
        return in_array($array['module'] ?? null, $this->modules)
            && ((empty($array['permission'])) || ($this->user && $this->user->isAbleTo($array['permission'])));
    }
}
