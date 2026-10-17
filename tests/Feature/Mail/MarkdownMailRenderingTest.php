<?php

namespace Tests\Feature\Mail;

use App\Mail\CommonEmailTemplate;
use Tests\TestCase;

/**
 * THE ONLY COVERAGE league/commonmark HAS IN THIS REPOSITORY, and it exists because the
 * dependency was upgraded and nothing exercised it.
 *
 * WHY THIS TEST WAS ADDED. composer.lock moved league/commonmark 2.8.0 -> 2.10.3 to clear 14
 * advisories, 3 of them reachable on the mail path. Before this file, `grep -il markdown tests/`
 * matched NOTHING: the whole 2053-case Feature suite never rendered a markdown mail, so a green
 * suite was not evidence about commonmark either way. "Run the suite against the upgrade" would
 * have produced a pass that proved nothing, which is worse than no claim at all.
 *
 * WHAT IT COVERS. `Mailable::markdown()` is the ONLY route from this codebase into commonmark,
 * used by five mailables: app/Mail/CommonEmailTemplate.php:43, app/Mail/ProdcutMail.php:38,
 * app/Mail/ProdcutStockMail.php:38, packages/workdo/EMailBox/.../ReplyMail.php:39 and
 * packages/workdo/CouponEmail/.../CouponEmailTemplate.php:36. commonmark is a TRANSITIVE
 * dependency -- laravel/framework requires ^2.7 and composer.json does not name it -- so this
 * renderer is the whole blast radius of the upgrade.
 *
 * Rendering one of the five drives the markdown pipeline end to end: the mail:: components in
 * resources/views/email/common_email_template.blade.php are themselves markdown, so the
 * conversion runs whether or not the injected content contains any.
 *
 * WHAT IT DOES NOT CLAIM. Passing here does not audit the 14 advisories; it shows the one path
 * that reaches the library still renders. It is also deliberately NOT pinned to a commonmark
 * version, so it is evidence on whichever version is installed -- 2.8.0 in the container where
 * the GitHub archive for 2.10.3 cannot be fetched, 2.10.3 in CI, which installs from the lock.
 */
class MarkdownMailRenderingTest extends TestCase
{
    public function test_a_markdown_mailable_renders_through_commonmark(): void
    {
        $template = (object) [
            'from' => 'noreply@example.test',
            'subject' => 'Order received',
            // markdown in the injected content, so the conversion is observable in the output
            'content' => "A **bold** word and a [link](https://example.test).",
        ];

        $rendered = (new CommonEmailTemplate(
            $template,
            ['MAIL_FROM_ADDRESS' => 'noreply@example.test'],
            ['name' => 'Test Store', 'email' => 'store@example.test'],
        ))->render();

        // THE MARKDOWN BECAME HTML, which only commonmark does here. Asserted on the tag
        // boundaries rather than on `<strong>bold</strong>`, because Laravel's CSS inliner runs
        // AFTER the conversion and rewrites every tag with a long inline `style` attribute -- the
        // first version of this test looked for the bare tag and failed on styled markup that
        // was in fact correct.
        $this->assertStringContainsString('>bold</strong>', $rendered);
        $this->assertStringContainsString('href="https://example.test"', $rendered);
        $this->assertStringContainsString('>link</a>', $rendered);
        // and the layout rendered around it rather than the raw component syntax surviving
        $this->assertStringContainsString('Test Store', $rendered);
        $this->assertStringNotContainsString('@component', $rendered);
    }
}
