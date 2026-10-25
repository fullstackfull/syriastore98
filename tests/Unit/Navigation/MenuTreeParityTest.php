<?php

namespace Tests\Unit\Navigation;

use Tests\TestCase;

/**
 * The same parity question as
 * tests/Support/Security/bin/verify-menu-render-parity.php, asked through the
 * REAL Blade engine instead of a stand-in compiler.
 *
 * Both exist on purpose. The script runs with no `vendor/` -- which is the
 * state this container is stuck in, see
 * docs/development/phase-2/PHASE-02-TEST-EVIDENCE.md §0 -- and proves the
 * algorithm and the markup agree. This test proves the SHIPPED partial, compiled
 * by Blade and resolved through the real view finder, renders what menuTree()
 * describes. A stand-in compiler that quietly disagreed with Blade would make
 * the script's green meaningless, and this is what would catch that.
 */
class MenuTreeParityTest extends TestCase
{
    /** A registry shaped like the one CompanyMenuListener builds. */
    private function registry(): array
    {
        return [
            ['title' => 'Sell',      'icon' => 'cart',    'name' => 'sell', 'parent' => null,   'order' => 1, 'route' => ''],
            ['title' => 'Orders',    'icon' => 'x',       'name' => 'o',    'parent' => 'sell', 'order' => 1, 'route' => 'order.index'],
            ['title' => 'Catalogue', 'icon' => 'package', 'name' => 'cat',  'parent' => null,   'order' => 2, 'route' => ''],
            ['title' => 'Products',  'icon' => 'x',       'name' => 'p',    'parent' => 'cat',  'order' => 2, 'route' => 'product.index'],
            ['title' => 'Brands',    'icon' => 'x',       'name' => 'b',    'parent' => 'cat',  'order' => 1, 'route' => 'product-brand.index'],
        ];
    }

    public function test_menu_tree_orders_by_order_not_by_array_position(): void
    {
        $tree = menuTree($this->registry(), null);

        $this->assertCount(2, $tree);
        $this->assertSame('sell', $tree[0]['name']);
        $this->assertSame('cat', $tree[1]['name']);

        // Brands is order 1 and appears SECOND in the array.
        $this->assertSame('b', $tree[1]['children'][0]['name']);
        $this->assertSame('p', $tree[1]['children'][1]['name']);
    }

    public function test_an_item_without_a_route_falls_back_to_the_hash_bang(): void
    {
        $tree = menuTree($this->registry(), null);
        $this->assertSame('#!', $tree[0]['href']);
    }

    public function test_only_top_level_items_are_marked_top(): void
    {
        $tree = menuTree($this->registry(), null);
        $this->assertTrue($tree[0]['isTop']);
        $this->assertFalse($tree[0]['children'][0]['isTop']);
    }

    /**
     * The DOM contract the inline sidebar search depends on.
     *
     * layouts/app.blade.php:358-398 selects `.dash-navbar > .dash-item` with a
     * DIRECT-CHILD combinator. A wrapper element breaks search with no error,
     * so the nesting is asserted, not just the class names.
     */
    public function test_the_rendered_dom_keeps_every_selector_the_sidebar_search_needs(): void
    {
        $html = generateMenu($this->registry(), null);

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<ul class="dash-navbar">' . $html . '</ul>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $x = new \DOMXPath($doc);

        $this->assertSame(2, $x->query('//ul[contains(@class,"dash-navbar")]/li[contains(@class,"dash-item")]')->length,
            'the two top-level items must be DIRECT children of .dash-navbar');
        $this->assertSame(2, $x->query('//li[contains(@class,"dash-hasmenu")]')->length);
        $this->assertSame(5, $x->query('//a[contains(@class,"dash-link")]')->length);
        $this->assertSame(2, $x->query('//a/span[contains(@class,"dash-mtext")]')->length,
            'only top-level items carry .dash-mtext, as before');
        $this->assertSame(2, $x->query('//li[contains(@class,"dash-item")]/ul[contains(@class,"dash-submenu")]')->length,
            '.dash-submenu must be a direct child of its .dash-item');
        $this->assertSame(2, $x->query('//a/span[contains(@class,"dash-arrow")]')->length);
    }

    /**
     * The one deliberate behaviour change: the title is escaped.
     *
     * The string builder interpolated __($item['title']) straight into HTML, so
     * a title carrying markup rendered as live markup.
     *
     * HOW FAR THIS ACTUALLY REACHES, corrected after review: every title that
     * reaches generateMenu() is a hardcoded literal inside __() in a module
     * listener, and no lang file value contains markup -- so this is HARDENING,
     * not the closure of a live XSS. All three generateMenu() returns in
     * getSideMenu() (helper.php:2138, 2142, 2167) come BEFORE the push of
     * database-sourced SideMenuOption rows at :2170, and that path ends in
     * generateSideMenu() at :2189. Editable titles therefore still render
     * unescaped at helper.php:2267, in a function this change does not touch.
     * Recorded in the ledger; it needs its own change.
     */
    public function test_a_menu_title_carrying_markup_is_escaped(): void
    {
        $html = generateMenu([[
            'title' => 'Tom & Jerry <b>bold</b>',
            'icon' => 'home', 'name' => 'x', 'parent' => null, 'order' => 1,
            'route' => 'dashboard',
        ]], null);

        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    /**
     * A parent whose only child is excluded gets no arrow and no empty list.
     *
     * The old string builder asked hasChildren() -- "does any item name this
     * as parent?" -- and then rendered a list that filters, so it emitted a
     * .dash-arrow and an empty <ul class="dash-submenu"></ul>: a disclosure
     * control opening onto nothing. menuTree() derives both from the same
     * value, so it cannot disagree with itself.
     */
    public function test_a_parent_whose_only_child_is_excluded_gets_no_arrow(): void
    {
        $html = generateMenu([
            ['title' => 'Settings', 'icon' => 'settings', 'name' => 'cfg', 'parent' => null, 'order' => 1, 'route' => ''],
            ['title' => 'Mobile', 'icon' => 'x', 'name' => 'mobilescreensetting', 'parent' => 'cfg', 'order' => 1, 'route' => 'dashboard'],
        ], null);

        $this->assertStringContainsString('dash-mtext', $html, 'the parent itself still renders');
        $this->assertStringNotContainsString('dash-arrow', $html);
        $this->assertStringNotContainsString('dash-submenu', $html);
    }

    /** The mobile-only item stays excluded unless IS_MOBILE is yes. */
    public function test_mobilescreensetting_is_excluded_when_not_a_mobile_build(): void
    {
        $tree = menuTree([
            ['title' => 'Dashboard', 'icon' => 'home', 'name' => 'dash', 'parent' => null, 'order' => 1, 'route' => 'dashboard'],
            ['title' => 'Mobile', 'icon' => 'x', 'name' => 'mobilescreensetting', 'parent' => null, 'order' => 2, 'route' => 'dashboard'],
        ], null);

        $this->assertCount(1, $tree);
        $this->assertSame('dash', $tree[0]['name']);
    }
}
