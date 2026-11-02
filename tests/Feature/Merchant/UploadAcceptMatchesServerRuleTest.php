<?php

namespace Tests\Feature\Merchant;

use App\Support\UploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F190 — THE PICKER AND THE VALIDATOR SAY THE SAME THING.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE DEFECT THIS CLOSES, which was the live half of F190 after D-29.5
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The per-field SERVER rules landed under D-29.5: `config/uploads.php` gives `preview_video`
 * `mimes:mp4,webm,mov` + `extensions:mp4,webm,mov` + `max:51200`, and `UploadRules::for()` refuses
 * to apply a list that would drop the size cap or validate content without validating the name.
 *
 * **The four file inputs never got the message.** All of them carried the same generic
 * `accept="{{ $ssUpload['accept'] }}"` — `UploadLimits::forStore()`'s platform-wide list, which is
 * the IMAGE list. So on the video field the browser offered a merchant jpg, png, csv and pdf, every
 * one of which the server refuses, and did NOT offer mp4, webm or mov, the only three that can
 * succeed. Every outcome of using the control as presented was a refusal.
 *
 * The owner's decision asks for *"field-specific validation/accept rules matching actual permitted
 * backend behavior"*. These cases assert the MATCH rather than the values, so the two cannot drift:
 * the accept attribute is derived from the same `extensions:` rule the validator receives.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS DELIBERATELY NOT ASSERTED
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * No case here widens a list. The owner's instruction is explicit — *"Do not expand
 * downloadable-product or preview-video file capabilities without verified safety and an explicit
 * approved list"* — so the only lists driven are the ones D-29.5 approved, and
 * `downloadable_product` stays behind its F217 gate. The gate's CLOSED state is tested as carefully
 * as its open one, because closed is how it ships.
 */
class UploadAcceptMatchesServerRuleTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The `extensions:` list the server will actually enforce for a field, or null if none. */
    private function serverExtensions(string $field): ?array
    {
        foreach (UploadRules::for($field) as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'extensions:')) {
                return array_map('trim', explode(',', substr($rule, strlen('extensions:'))));
            }
        }

        return null;
    }

    /**
     * THE VIDEO FIELD ADVERTISES EXACTLY WHAT THE SERVER TAKES, and nothing else.
     *
     * Asserted as a derivation from the server rule rather than against the literal
     * `.mp4,.webm,.mov`, so widening the approved list in `config/uploads.php` moves both halves at
     * once and this test keeps holding rather than needing an edit.
     */
    public function test_the_video_field_advertises_the_server_list(): void
    {
        $extensions = $this->serverExtensions('preview_video');

        $this->assertNotNull($extensions,
            'premise: preview_video has no server `extensions:` rule, so D-29.5 is not in force and '
            . 'there is nothing for the picker to match');

        $expected = implode(',', array_map(fn ($e) => '.' . $e, $extensions));

        $this->assertSame($expected, UploadRules::accept('preview_video', '.jpg,.png'),
            'the video picker does not advertise the server list. Before F190 it advertised the '
            . 'platform IMAGE list, so every type it offered was refused and the three that work '
            . 'were not offered.');

        /* AND IT MUST NOT ADVERTISE AN IMAGE TYPE, which is the specific symptom. Checked by name
           because "matches the server rule" would also be satisfied if the server rule itself were
           widened to images by accident. */
        foreach (['.jpg', '.jpeg', '.png', '.csv', '.pdf', '.svg'] as $notAVideo) {
            $this->assertStringNotContainsString($notAVideo,
                UploadRules::accept('preview_video', '.jpg,.png'),
                "the video field offers {$notAVideo}, which the server refuses");
        }
    }

    /**
     * THE PLATFORM FALLBACK IS THE RIGHT ANSWER FOR A GATED FIELD, not a default.
     *
     * `downloadable_product` is held off by the owner's F217 gate, so `UploadRules::for()` returns
     * `[]` and `Utility::upload_file()` falls back to the platform-wide list. The picker therefore
     * has to advertise that same platform list — anything narrower would hide types the server
     * would accept, and anything wider would offer types it refuses.
     */
    public function test_a_gated_field_advertises_the_platform_list_the_server_will_use(): void
    {
        $this->assertSame([], UploadRules::for('downloadable_product'),
            'premise: the F217 gate is open in this environment, so this case is measuring the '
            . 'wrong state. The gate opens only on an unambiguous yes in '
            . 'UPLOAD_DIGITAL_TYPES_ENABLED.');

        $platform = '.jpg,.jpeg,.png,.csv,.svg,.pdf';

        $this->assertSame($platform, UploadRules::accept('downloadable_product', $platform),
            'a gated field is not advertising the platform list, so the picker and the server '
            . 'disagree in the direction that hides working types from the merchant');
    }

    /**
     * AND WHEN THE GATE OPENS, THE PICKER FOLLOWS WITH NO SECOND EDIT.
     *
     * The thing most likely to go wrong when F217 is closed is that somebody flips
     * `UPLOAD_DIGITAL_TYPES_ENABLED`, the server starts accepting zip/epub/mp3, and the picker goes
     * on offering the image list — the same disagreement as before, one field over. Driven by
     * setting the config directly rather than the env, because `config/uploads.php` parses the env
     * once at boot.
     */
    public function test_when_the_gate_opens_the_picker_follows(): void
    {
        $approved = config('uploads.downloadable_product.rules');

        config(['uploads.downloadable_product' => [
            'enabled' => true,
            'rules' => $approved,
        ]]);

        $extensions = $this->serverExtensions('downloadable_product');

        $this->assertNotNull($extensions, 'premise: the approved rules carry no extensions: list');

        $this->assertSame(
            implode(',', array_map(fn ($e) => '.' . $e, $extensions)),
            UploadRules::accept('downloadable_product', '.jpg,.png'),
            'with the gate open the picker still advertises the platform list, so a merchant '
            . 'cannot select the types the server now accepts'
        );

        /* AND STILL NO SVG, which is the one type the decision names for containment. */
        $this->assertStringNotContainsString('.svg',
            UploadRules::accept('downloadable_product', '.jpg,.png'),
            'the approved digital list now offers SVG, which can carry script and is served '
            . 'same-origin');
    }

    /**
     * A MALFORMED CONFIG FALLS BACK RATHER THAN ADVERTISING NOTHING.
     *
     * `UploadRules::for()` already refuses a list that drops `max:` or validates content without
     * the name, returning `[]` so the platform list stays in force. The accept reader has to agree
     * with that: an empty attribute means "no restriction" to a browser, which would be the
     * opposite of what a refused config should produce.
     */
    public function test_a_refused_config_advertises_the_platform_list_not_nothing(): void
    {
        $platform = '.jpg,.png';

        foreach ([
            'no max rule' => ['mimes:mp4', 'extensions:mp4'],
            'content without name' => ['mimes:mp4', 'max:1024'],
            'empty rules' => [],
            'extensions with no values' => ['mimes:mp4', 'extensions:', 'max:1024'],
        ] as $label => $rules) {
            config(['uploads.preview_video' => ['enabled' => true, 'rules' => $rules]]);

            $this->assertSame($platform, UploadRules::accept('preview_video', $platform),
                "a config refused for \"{$label}\" produced something other than the platform "
                . 'fallback. An empty accept attribute means NO restriction to a browser, so '
                . 'getting this wrong offers every type on disk.');
        }
    }

    /**
     * THE FOUR INPUTS ACTUALLY USE IT — a ratchet, because the reader above is only worth having if
     * the views call it.
     *
     * Source-level because rendering the product editor needs a product, a store, categories,
     * brands, taxes and a permission set, and what is at risk is one attribute on four inputs. The
     * behaviour of the reader itself is driven above.
     */
    public function test_all_four_file_inputs_ask_for_their_own_list(): void
    {
        foreach (['create', 'edit'] as $view) {
            $source = file_get_contents(resource_path("views/product/{$view}.blade.php"));

            $this->assertSame(2, substr_count($source, 'UploadRules::accept('),
                "product/{$view}.blade.php does not call UploadRules::accept() exactly twice — one "
                . 'for downloadable_product and one for preview_video. A field left on the generic '
                . '`$ssUpload[\'accept\']` advertises the platform image list, which is the defect '
                . 'F190 names.');

            foreach (['downloadable_product', 'preview_video'] as $field) {
                $this->assertStringContainsString("UploadRules::accept('{$field}'", $source,
                    "product/{$view}.blade.php does not derive {$field}'s accept from its own rule");
            }

            /*
             * AND THE FALLBACK IS PASSED ON EACH CALL, so a gated or refused field advertises the
             * platform list rather than an empty attribute — which a browser reads as NO
             * restriction.
             *
             * Matched PER FIELD rather than counted. A `substr_count` of
             * `$ssUpload['accept'])` reads 3 in these views, not 2: three other inputs (cover
             * image and the gallery) legitimately use the bare platform list, and one of them is
             * inside a nested call whose `))` contains the needle. Counting a substring that also
             * occurs in unrelated correct code is how a ratchet starts asserting the wrong number.
             */
            foreach (['downloadable_product', 'preview_video'] as $field) {
                $this->assertMatchesRegularExpression(
                    '/UploadRules::accept\(\x27' . $field . '\x27,\s*\$ssUpload\[\x27accept\x27\]\)/',
                    $source,
                    "product/{$view}.blade.php does not pass the platform fallback to {$field}'s "
                    . 'accept call, so a gated or refused config would render an EMPTY accept '
                    . 'attribute — which offers every file on disk instead of none'
                );
            }
        }
    }

    /**
     * THE SERVER RULE IS STILL THE ENFORCEMENT, AND THE ACCEPT IS ONLY A HINT.
     *
     * The owner's instruction is explicit: *"Enforce restrictions on the SERVER, not only HTML
     * input attributes."* `accept` is trivially bypassed — a merchant can drag any file in, or post
     * the form directly. So this pins that the server rules are still there and still carry all
     * three parts, which is what actually refuses a bad upload.
     */
    public function test_the_server_rule_still_carries_content_name_and_size(): void
    {
        $rules = UploadRules::for('preview_video');

        $this->assertNotEmpty($rules, 'premise: the video field has no server rules at all');

        foreach (['mimes:', 'extensions:', 'max:'] as $part) {
            $this->assertTrue(
                collect($rules)->contains(fn ($r) => is_string($r) && str_starts_with($r, $part)),
                "the server rule for preview_video lost its `{$part}` part. `mimes:` checks the "
                . 'bytes, `extensions:` checks the name the web server will act on, and `max:` is '
                . 'the size cap that disappears entirely if the rule array is replaced without it.'
            );
        }
    }
}
