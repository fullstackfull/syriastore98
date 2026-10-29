<?php

namespace Tests\Unit\Ui;

use App\Models\OrderNote;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * D-25 STEP 3 — the order detail page's shell on the design system.
 * =================================================================
 *
 * WHAT THE OWNER APPROVED. *"Step 3 — order detail page: finish the shell with the `ss-card` /
 * `ss-row` components that already exist, preserving order data, notes, deletion, status, invoices
 * and permissions; duplicated note-rendering branches may be unified **only if their equivalence is
 * proved**."*
 *
 * THE EQUIVALENCE WAS PROVED, AND THEN IT PROVED SOMETHING ELSE. The five `@elseif` branches
 * differed in exactly one token — the tone class. Normalising whitespace and substituting that
 * class made all five byte-identical, 912 characters each, so the collapse is sound and four
 * copies of the delete form are gone.
 *
 * But the chain had **no `@else`**, so a note matching none of the five rendered nothing — and
 * that is reachable. `OrderNote::addOrderNote()` writes the three statuses the view knows;
 * `OrderController::store()` writes `'Order created'` with a **lowercase c**, and
 * `Api\DashboardController` writes the same plus `'Customer note'` / `'customer'`. MEASURED on the
 * running page with all eight shapes present for one order: **5 note blocks rendered of 8 rows**,
 * and the three inserted texts absent from `document.body.innerText`. That is **F180**, and Step 3
 * REPRODUCES it rather than fixing it, because Step 3's condition is to preserve.
 *
 * THE DEPENDENCY THIS FILE EXISTS TO PROTECT. `custom.css` and `rtl-custom.css` tint the five note
 * tones through `.card-body .card-header.<tone>` — **twenty selectors across four stylesheets**
 * once the `html[data-ss-theme="dark"]` variants are counted, and those dark variants are
 * P2-04-6's measured fix for tints that read 1.24–2.95:1. So the notes panel's body keeps
 * `card-body` as the ancestor hook AND gains `ss-card__body`, and the note item itself stays on
 * Bootstrap's `card` / `card-header`. Renaming either class removes every tint silently — the
 * notes still render, in no colour at all. A later tidy-up would look entirely reasonable.
 */
class OrderDetailShellContractTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'resources/views/order/view.blade.php';

    private const TONES = [
        'stock-mange-note',
        'order-create-note',
        'order-status-note',
        'admin-private-note',
        'customer-note',
    ];

    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function noteShapes(): array
    {
        return [
            // the five the page renders
            'stock manage' => ['Stock Manage', null, 'stock-mange-note'],
            'order created (capital C)' => ['Order Created', null, 'order-create-note'],
            'order status change' => ['Order status change', null, 'order-status-note'],
            'private note' => ['', 'private_note', 'admin-private-note'],
            'note to customer' => ['', 'to_customer', 'customer-note'],
            // F180: the three the page drops, each written by a real handler
            'OrderController::store' => ['Order created', 'created', null],
            'admin API order created' => ['Order created', 'system', null],
            'admin API customer note' => ['Customer note', 'customer', null],
        ];
    }

    /** @dataProvider noteShapes */
    public function test_the_tone_method_reproduces_the_old_branch_chain(
        string $status,
        ?string $type,
        ?string $expected
    ): void {
        $note = new OrderNote();
        $note->status = $status;
        $note->note_type = $type;

        $this->assertSame(
            $expected,
            $note->timelineTone(),
            $expected === null
                ? sprintf(
                    'status=%s note_type=%s must return NULL. The old chain had no `@else`, so this'
                    . " shape rendered nothing, and Step 3 is required to PRESERVE that — fixing\n"
                    . 'F180 is its own slice.',
                    var_export($status, true),
                    var_export($type, true)
                )
                : sprintf(
                    'status=%s note_type=%s rendered in %s before the five branches were collapsed',
                    var_export($status, true),
                    var_export($type, true),
                    $expected
                )
        );
    }

    public function test_the_five_note_branches_are_now_one_block(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertSame(
            1,
            substr_count($source, 'note-bg-color'),
            'the note loop renders more than one block again. Five near-identical branches were'
            . ' collapsed into one after proving them byte-identical; a second block means the'
            . ' duplication is back.'
        );

        $this->assertSame(
            1,
            substr_count($source, "'route' => ['order-note.destroy'"),
            'there is more than one delete form in the note loop — the duplication the collapse'
            . ' removed'
        );

        /* The tone now comes from the model, so the view carries no status literal of its own.
           A reintroduced literal is the duplication coming back one branch at a time. */
        foreach (['Stock Manage', 'Order status change', 'private_note', 'to_customer'] as $literal) {
            $this->assertStringNotContainsString(
                "\$note->status == '" . $literal . "'",
                $source,
                'the view decides a note tone again; `OrderNote::timelineTone()` owns that, so'
                . ' that the fix for F180 has one place to change'
            );
        }

        $this->assertStringContainsString(
            '$note->timelineTone()',
            $source,
            'the view no longer asks the model for the tone'
        );
    }

    public function test_the_note_tone_hook_survives_the_card_conversion(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        /*
         * THE SUBTLE ONE. The stylesheets tint a note through `.card-body .card-header.<tone>`,
         * so BOTH names are load-bearing: the panel's body must still be a `card-body`, and the
         * note's own header must still be a `card-header`. Converting either to its `ss-`
         * equivalent leaves the notes rendering in no colour at all.
         */
        $this->assertStringContainsString(
            'class="card-body ss-card__body"',
            $source,
            "the notes panel's body lost its `card-body` hook.\n"
            . "The five tone tints are selected as `.card-body .card-header.<tone>` in custom.css\n"
            . "and rtl-custom.css, light and dark — twenty selectors. Without the ancestor they\n"
            . 'all stop matching and every note renders untinted.'
        );

        $this->assertMatchesRegularExpression(
            '/<div class="card-header note-bg-color \{\{ \$ssNoteTone \}\}">/',
            $source,
            "the note item's `card-header` was renamed, which removes all five tints"
        );

        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $stylesheet) {
            $css = (string) file_get_contents(base_path($stylesheet));

            foreach (self::TONES as $tone) {
                $this->assertStringContainsString(
                    '.card-body .card-header.' . $tone,
                    $css,
                    $stylesheet . ": the `{$tone}` tint no longer selects what the view renders."
                    . ' If the rules moved to `ss-` selectors the view has to move with them, in'
                    . ' the same change — a tint that selects nothing fails silently.'
                );
            }
        }
    }

    public function test_the_address_lists_are_design_system_definition_lists(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertSame(2, substr_count($source, '<dl class="ss-deflist">'), 'expected two address lists');
        $this->assertSame(14, substr_count($source, '<dt class="ss-deflist__key">'), 'expected 14 labels');
        $this->assertSame(14, substr_count($source, '<dd class="ss-deflist__val">'), 'expected 14 values');

        /* Bootstrap's 12-column split is gone: `.ss-deflist` is a two-column grid, which is
           direction-aware without an RTL override. */
        $this->assertStringNotContainsString('col-sm-3', $source, 'the address lists are back on the bootstrap grid');
        $this->assertStringNotContainsString('col-sm-9', $source, 'the address lists are back on the bootstrap grid');
    }

    public function test_the_page_shell_uses_design_system_cards(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertGreaterThanOrEqual(4, substr_count($source, '"ss-card'), 'fewer DS cards than the shell has');

        /*
         * EXACTLY THREE BOOTSTRAP CARD ELEMENTS SURVIVE, each with a stylesheet rule depending on
         * it, and the assertion is on the COUNT of each rather than on membership of an allow-list.
         *
         * The first version allowed any number of each, and a bite proved it worthless: turning a
         * shell `ss-card` back into `card` produced a second `class="card"`, which the allow-list
         * happily accepted. An allow-list cannot tell one permitted element from five.
         */
        preg_match_all('/\bclass="([^"]*)"/', $source, $attrs);

        $expected = [
            'card' => 1,                                        // the note item's own wrapper
            'card-header note-bg-color {{ $ssNoteTone }}' => 1, // the tinted note header
            'card-body ss-card__body' => 1,                     // the tints' required ancestor
        ];

        $counts = [];
        $unexpected = [];
        foreach ($attrs[1] as $attr) {
            $tokens = preg_split('/\s+/', trim($attr)) ?: [];
            if (! array_intersect($tokens, ['card', 'card-header', 'card-body'])) {
                continue;
            }
            $normalised = trim($attr);
            if (! array_key_exists($normalised, $expected)) {
                $unexpected[] = $attr;
                continue;
            }
            $counts[$normalised] = ($counts[$normalised] ?? 0) + 1;
        }

        $this->assertSame(
            [],
            $unexpected,
            "a bootstrap card class the tone selectors do NOT need survived the shell conversion.\n"
            . 'Only the note item, its header and the notes panel body keep them, and each has a'
            . " stylesheet rule depending on it.\n  " . implode("\n  ", $unexpected)
        );

        foreach ($expected as $attr => $times) {
            $this->assertSame(
                $times,
                $counts[$attr] ?? 0,
                sprintf(
                    'expected exactly %d element(s) with class="%s", found %d. More means a shell'
                    . ' card was left on bootstrap; fewer means a tint hook was lost.',
                    $times,
                    $attr,
                    $counts[$attr] ?? 0
                )
            );
        }
    }

    public function test_the_page_header_actions_are_named_and_translated(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $start = strpos($source, "@section('action-button')");
        $this->assertNotFalse($start);
        $end = strpos($source, '@endsection', $start);
        $this->assertNotFalse($end);
        $region = substr($source, $start, $end - $start);

        preg_match_all('/<a\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*?)>/s', $region, $tags, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(4, count($tags), 'fewer controls than this region holds');

        foreach ($tags as $tag) {
            $attrs = $tag[1];
            $short = trim(preg_replace('/\s+/', ' ', substr($tag[0], 0, 100)));

            $this->assertStringContainsString('ss-btn', $attrs, "not a DS button: {$short}");

            foreach (['btn-primary', 'btn-icon', 'btn-badge'] as $legacy) {
                $this->assertStringNotContainsString($legacy, $attrs, "bootstrap class survived: {$short}");
            }

            $this->assertMatchesRegularExpression(
                '/\baria-label="\{\{\s*__\(/',
                $attrs,
                "a control has no TRANSLATED accessible name: {$short}\n"
                . 'Three of these carried bare English strings — `title="Print"`,'
                . ' `aria-label="Payment Receipt"` — although both lines are already in ar.json.'
                . ' The view simply was not calling the translator, so the Arabic page said'
                . ' "Print".'
            );
        }

        /* The copy-link control's two hooks: the url it copies and the handler that reads it. */
        $this->assertStringContainsString('data-ss-copy="{{ config(\'app.url\')', $region, 'the copy-link url hook changed');
        $this->assertStringContainsString('onclick="copyToClipboard(this)"', $region, 'the copy-link handler changed');
    }
}
