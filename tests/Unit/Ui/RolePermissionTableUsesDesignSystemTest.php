<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE ROLE-PERMISSION MATRIX WENT THROUGH THE DESIGN SYSTEM.  P2.5-WAVE-12
 * =================================================================================================
 *
 * roles/create.blade.php and roles/edit.blade.php are the entire role-permission UI -- an xl modal
 * matrix of every module against its permissions, with a checkbox per cell. Both rendered a
 * hand-written `<table class="table table-striped" id="dataTable-1">` outside the `.ss-dt-card-body`
 * scope Wave 1 styled, so reached by none of it. (`id="dataTable-1"` is inert here: custom.js
 * initialises the `.dataTable` CLASS, which this table does not carry, so it is a static matrix.)
 *
 * Both now carry `.ss-table`, and `table-striped` is REMOVED rather than kept beside it: the design
 * system's header band and row rule already separate the modules, and zebra striping on a
 * checkbox matrix fights the row rule for the eye instead of helping it. Verified on the running
 * application in all four combinations (evidence/wave-12), opened the way a merchant opens them --
 * the Create button on /roles and the per-row edit action in the roles DataTable.
 *
 * VISUAL ONLY. No permission, no module, no role, no gate changed -- the diff is the table class on
 * two lines. The checkboxes, their names and their values are untouched.
 *
 * THE BITE. Reverting either table to `class="table table-striped"` fails
 * test_both_role_tables_are_design_system_tables (ss-table gone) AND
 * test_neither_role_table_keeps_the_zebra_striping (table-striped back).
 */
class RolePermissionTableUsesDesignSystemTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    private const ROLE_TABLES = [
        'resources/views/roles/create.blade.php' => 1,
        'resources/views/roles/edit.blade.php' => 1,
    ];

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> */
    private function tableTags(string $source): array
    {
        preg_match_all('/<table\b[^>]*>/s', $source, $m);

        return $m[0];
    }

    /** PREMISE. `.ss-table` must be a real rule, or asserting the markup uses it proves nothing. */
    public function test_the_design_system_table_rule_exists(): void
    {
        $this->assertStringContainsString(
            '.ss-table thead th',
            $this->read(self::SHEET),
            'the .ss-table system is gone from the stylesheet'
        );
    }

    /** Both role modals render a design-system table. */
    public function test_both_role_tables_are_design_system_tables(): void
    {
        foreach (self::ROLE_TABLES as $view => $expected) {
            $tags = $this->tableTags($this->read($view));

            $this->assertGreaterThanOrEqual(
                $expected,
                count($tags),
                "premise: {$view} renders " . count($tags) . " <table>, fewer than the {$expected} measured"
            );

            foreach ($tags as $i => $tag) {
                $this->assertMatchesRegularExpression(
                    '/\bss-table\b/',
                    $tag,
                    "table #{$i} in {$view} does not carry .ss-table: {$tag}"
                );
            }
        }
    }

    /** The zebra striping is gone -- the design system's row rule replaces it, it does not join it. */
    public function test_neither_role_table_keeps_the_zebra_striping(): void
    {
        foreach (array_keys(self::ROLE_TABLES) as $view) {
            foreach ($this->tableTags($this->read($view)) as $tag) {
                $this->assertStringNotContainsString(
                    'table-striped',
                    $tag,
                    "{$view} keeps Bootstrap `table-striped` beside .ss-table, so the matrix carries "
                        . 'two row treatments at once'
                );
            }
        }
    }
}
