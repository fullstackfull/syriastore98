<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * P2-05-4 — `buildCategoryTree()` builds a string that is PRINTED AS MARKUP, so the
 * merchant-written name inside it has to be escaped where the string is built.
 *
 * THE SINK, measured rather than assumed. Eight views render this value with `{!! !!}`:
 * `category/{create,edit}`, `testimonial/{create,edit}`, `product/{create,edit}` and
 * `product-review/{create,edit}` in the vendored `packages/workdo/ProductReview`. Not one uses
 * `{{ }}`, and no PHP consumer re-escapes it — both checked before this test was written, because
 * escaping in the helper would turn a `{{ }}` consumer's indent into a literal `&nbsp;`.
 *
 * WHY THE HELPER AND NOT THE VIEWS. The helper is what mixes the two kinds of string: its
 * `$prefix` is deliberate markup (`&nbsp;` repeated per level, which is how the select shows
 * hierarchy) and `$category->name` is merchant input. A value that is part markup by design
 * cannot be escaped at the point of printing without losing the indent, so the only place the
 * distinction survives is where it is made. It is also the only fix that reaches the two vendored
 * views, which must not be edited.
 *
 * WHO CAN REACH IT. A category name is written by the merchant's own staff — anyone holding
 * `Create Product Category`. It renders into the parent-category select of every category,
 * product and testimonial form the store's other users open, including the owner's. So it is a
 * privilege-escalation path WITHIN a tenant rather than across tenants: the same class as F98,
 * found on the screen P2-05-4 converts.
 */
class CategoryTreeEscapingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('buildCategoryTree')) {
            require_once dirname(__DIR__, 3) . '/app/Helper/helper.php';
        }
    }

    /** A plain object is enough: the helper only reads id, parent_id and name. */
    private function category(int $id, $parentId, string $name): object
    {
        return (object) ['id' => $id, 'parent_id' => $parentId, 'name' => $name];
    }

    public function test_a_name_containing_markup_is_escaped(): void
    {
        $tree = buildCategoryTree([
            $this->category(1, null, '<img src=x onerror=alert(1)>'),
        ]);

        $this->assertCount(1, $tree);

        $this->assertStringNotContainsString('<img', $tree[0]['name'],
            'buildCategoryTree() returns a string that eight views print with {!! !!}, so an '
            . 'unescaped category name is stored XSS in the parent-category select of every '
            . 'category, product and testimonial form in the store.');

        $this->assertStringContainsString('&lt;img', $tree[0]['name'],
            'the name must be HTML-escaped, not stripped — a merchant whose category is genuinely '
            . 'called "Shoes < 40" must still see it written that way.');
    }

    /**
     * THE OTHER HALF, and the reason this cannot simply be `e()` at the call site: the indent
     * prefix MUST stay live markup or every option in the select loses its hierarchy.
     */
    public function test_the_indent_prefix_is_still_rendered_as_markup(): void
    {
        $tree = buildCategoryTree([
            $this->category(1, null, 'Parent'),
            $this->category(2, 1, 'Child'),
        ]);

        $this->assertSame('Parent', $tree[0]['name']);
        $this->assertSame('&nbsp;&nbsp;&nbsp;Child', $tree[1]['name'],
            'the child must keep its raw &nbsp; indent: escaping the whole string would print '
            . '"&amp;nbsp;" to the merchant instead of indenting the option.');
    }

    /** An ampersand in a real name must not be able to forge the indent's entity. */
    public function test_an_ampersand_in_a_name_cannot_forge_the_indent(): void
    {
        $tree = buildCategoryTree([
            $this->category(1, null, '&nbsp;Sneakers'),
        ]);

        $this->assertSame('&amp;nbsp;Sneakers', $tree[0]['name']);
    }

    /** Quotes matter here specifically: the value is printed INSIDE an <option> element. */
    public function test_quotes_in_a_name_are_escaped(): void
    {
        $tree = buildCategoryTree([
            $this->category(1, null, '"><script>alert(1)</script>'),
        ]);

        $this->assertStringNotContainsString('<script', $tree[0]['name']);
        $this->assertStringNotContainsString('">', $tree[0]['name']);
    }
}
