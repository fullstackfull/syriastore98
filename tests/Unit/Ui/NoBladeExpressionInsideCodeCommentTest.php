<?php

namespace Tests\Unit\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * F176 — NO BLADE EXPRESSION MAY HIDE INSIDE A JAVASCRIPT OR PHP COMMENT.
 * =======================================================================
 *
 * Blade compiles its own syntax anywhere in a template. An expression written inside a JavaScript
 * or PHP block comment is not a comment to Blade: it becomes live code that runs on every render,
 * inside what every later reader will take for prose.
 *
 * THIS HAS BITTEN TWICE, both times in the same family of views and both times from a comment
 * written while fixing something else:
 *
 *   - during F162, a comment that spelled out a loop directive in full made Blade raise
 *     "Malformed" and the view 500ed, so the probe measuring that fix reported zero fields — a
 *     broken view read as a clean result;
 *   - F176: a `data-id` example in a JavaScript comment in
 *     `attribute_combinations_data.blade.php` compiled to a live echo of `$str`, which exists
 *     only inside the combination loop. For a product with NO VARIANTS the loop never ran and the
 *     endpoint answered 500. MEASURED on the running app: editing a variant-less product produced
 *     `500 products/attribute_combination_data/edit` on every page load. It was silent because
 *     neither caller defines an AJAX error handler, and the empty panel that resulted looks
 *     exactly like the correct answer for a product with no variants — which is why it survived.
 *
 * THE RULE IS NARROWER THAN IT FIRST LOOKS, AND EVERY BOUNDARY WAS MEASURED AGAINST THE REAL
 * COMPILER RATHER THAN REASONED ABOUT. The first version of this file flagged four false
 * positives, among them a minified Tailwind block and a comment of my own that is in fact safe.
 * What `test_the_compiler_really_behaves_the_way_this_rule_assumes` pins:
 *
 *   | construct                                    | compiles? |
 *   |----------------------------------------------|-----------|
 *   | braces in a JavaScript comment               | YES       |
 *   | a parenthesised directive in a JS comment     | YES       |
 *   | `@if (...)` in a JS comment                   | YES       |
 *   | a BARE directive name, no parentheses          | no        |
 *   | anything inside a Blade `{{-- --}}` comment    | no        |
 *   | anything inside a `@php … @endphp` block       | no        |
 *
 * The last two are why the sweep skips those regions entirely: Blade strips its own comments
 * before compiling, and passes a raw PHP block through verbatim. The bare-name exemption is why
 * prose such as "the nine icons used to live in a @php block" is not an offence.
 *
 * The escape is Blade's own — prefixing the braces with `@` renders them literally, and that is
 * what the fixed comment uses.
 */
class NoBladeExpressionInsideCodeCommentTest extends TestCase
{
    /** @return list<string> every first-party Blade view */
    private function views(): array
    {
        $root = base_path('resources/views');
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The commented-out text of a template, with the regions Blade never compiles removed first.
     *
     * Blade comments and raw PHP blocks go first, because the compiler either strips or passes
     * them through — measured, not assumed. What is left is scanned for block and line comments,
     * and only the text INSIDE those comments is returned. Returning whole lines was the first
     * version's bug: a minified stylesheet whose comment opens and closes before any markup was
     * reported as an offender.
     *
     * The opener must not be preceded by a word character, a quote or a slash, so an `accept`
     * attribute's image wildcard does not open a false comment that swallows the rest of the
     * file. That exact mistake broke two ratchets in this repository.
     *
     * @return list<array{0:int,1:string}> line number, commented text
     */
    private function commentedText(string $source): array
    {
        $blank = static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]);

        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
        $source = preg_replace_callback('/@php\b(?!\s*\().*?@endphp/s', $blank, (string) $source);

        $out = [];
        $lineNo = 1;
        $inBlock = false;
        $buffer = '';
        $bufferLine = 0;
        $length = strlen((string) $source);

        for ($i = 0; $i < $length; $i++) {
            $two = substr((string) $source, $i, 2);

            if ($source[$i] === "\n") {
                if ($inBlock) {
                    $out[] = [$bufferLine, $buffer];
                    $buffer = '';
                    $bufferLine = $lineNo + 1;
                }

                $lineNo++;

                continue;
            }

            if (! $inBlock && $two === '/*') {
                $before = $i > 0 ? $source[$i - 1] : ' ';

                if (preg_match('/[\w"\'\/]/', $before) === 1) {
                    continue;   // an image wildcard or a regex, not a comment
                }

                $inBlock = true;
                $bufferLine = $lineNo;
                $i++;

                continue;
            }

            if ($inBlock && $two === '*/') {
                $out[] = [$bufferLine, $buffer];
                $buffer = '';
                $inBlock = false;
                $i++;

                continue;
            }

            if ($inBlock) {
                $buffer .= $source[$i];
            }
        }

        if ($inBlock && trim($buffer) !== '') {
            $out[] = [$bufferLine, $buffer];
        }

        return array_values(array_filter($out, static fn (array $row): bool => trim($row[1]) !== ''));
    }

    /** Blade syntax that the compiler really does act on. */
    private function liveSyntax(string $text): bool
    {
        /* `@{{` is Blade's escape and renders literally, so it is the fix, not the defect. */
        $text = (string) preg_replace('/@\{\{/', '', $text);

        return preg_match('/\{\{|\{!!/', $text) === 1
            || preg_match('/(?<![\w@])@[a-zA-Z]+\s*\(/', $text) === 1;
    }

    public function test_no_first_party_view_hides_a_blade_expression_in_a_code_comment(): void
    {
        $views = $this->views();

        $this->assertGreaterThan(
            200,
            count($views),
            'premise: the sweep found the view tree. A short list would make this vacuous.'
        );

        $offenders = [];

        foreach ($views as $path) {
            foreach ($this->commentedText((string) file_get_contents($path)) as [$line, $text]) {
                if ($this->liveSyntax($text)) {
                    $offenders[] = str_replace(base_path() . '/', '', $path) . ':' . $line
                        . '  ' . trim(preg_replace('/\s+/', ' ', $text));
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Blade compiles its own syntax inside a JavaScript or PHP comment, so each of these is\n"
            . "LIVE CODE wearing prose. Prefix the braces with `@` to render them literally, or move\n"
            . "the note into a Blade comment, which the compiler strips:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * THE COMPILER'S ACTUAL BEHAVIOUR, pinned — so this rule can neither forbid something
     * harmless nor permit something live without a named test failing.
     */
    public function test_the_compiler_really_behaves_the_way_this_rule_assumes(): void
    {
        $compiles = static fn (string $src): bool => str_contains(Blade::compileString($src), '<?php');

        $this->assertTrue(
            $compiles('<script>/* see {{ $x }} */</script>'),
            'Blade no longer compiles braces inside a JavaScript comment, so this rule may be obsolete'
        );
        $this->assertTrue(
            $compiles('<script>/* see @php(1) here */</script>'),
            'a parenthesised directive in a code comment no longer compiles'
        );
        $this->assertTrue(
            $compiles('<script>/* see @if ($x) here */</script>'),
            'a conditional directive in a code comment no longer compiles'
        );

        $this->assertFalse(
            $compiles('<script>/* in a @php block */</script>'),
            'a BARE directive name now compiles, so the exemption for prose is unsafe'
        );
        $this->assertFalse(
            $compiles('A{{-- a comment holding {{ $x }} --}}B'),
            'a Blade comment no longer shields its contents, so this rule is too narrow'
        );

        /* The escape, which is what the fixed comment relies on. */
        $this->assertStringNotContainsString(
            'echo e($x)',
            Blade::compileString('<script>/* escaped: @{{ $x }} */</script>'),
            'the brace escape no longer works, so the fix this test recommends does not'
        );
    }

    /**
     * AND THE DETECTOR'S OWN PREMISES, because a sweep that finds nothing passes either way.
     */
    public function test_the_detector_catches_a_real_offence_and_spares_the_measured_exemptions(): void
    {
        $offends = fn (string $src): bool => (bool) array_filter(
            $this->commentedText($src),
            fn (array $row): bool => $this->liveSyntax($row[1])
        );

        $this->assertTrue($offends('<script>/* see {{ $str }} here */</script>'), 'a real offence');
        /*
         * SINGLE QUOTES AND CONCATENATION, not a double-quoted string: PHP would interpolate
         * `$str` out of the fixture before the detector ever saw it, and this assertion then
         * failed for a reason that had nothing to do with the detector.
         */
        $this->assertTrue(
            $offends('<script>/* line one' . "\n" . '   holding {{ $str }} */</script>'),
            'an offence spread across lines'
        );

        $this->assertFalse($offends('<script>/* escaped @{{ $str }} */</script>'), 'the escape');
        $this->assertFalse($offends('A{{-- holding {{ $str }} --}}B'), 'a Blade comment');
        $this->assertFalse($offends("@php\n/* mentions @php(...) */\n\$a=1;\n@endphp"), 'inside a raw PHP block');
        $this->assertFalse($offends('<script>/* in a @php block */</script>'), 'a bare directive name');
        $this->assertFalse(
            $offends('<style>/* ! tailwindcss */*,::after{box-sizing:border-box}</style>' . "\n" . '<a>{{ $x }}</a>'),
            'markup after a comment that closed on the same line'
        );
        $this->assertFalse(
            $offends('<input accept="image/*" value="{{ $x }}">'),
            "an accept attribute's wildcard must not open a comment"
        );
    }
}
