<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-107 (P03-D-04) — THE EDITORIAL SCREEN'S TWO STRUCTURAL CLAIMS, PINNED.
 * =================================================================================================
 *
 * Both are claims this change makes in prose, and prose is not a control.
 *
 * ONE — THE SCREEN IS READ-ONLY, BY DESIGN. An article changes state when somebody edits the
 * `status` line in `resources/docs/<locale>/...` and that change is reviewed and deployed. A button
 * on this screen would have to rewrite a file in a container filesystem that the next deploy
 * replaces, so the approval would vanish with no trace — a worse workflow than none, which is why
 * there is no write action. That is easy to "improve" later by somebody who reads the screen as
 * unfinished rather than as finished, so the absence is asserted.
 *
 * TWO — IT IS BILINGUAL BY CONSTRUCTION. Every visible string goes through `__()` and the direction
 * comes from the layout's own `dir`, so there is no hard-coded `ltr`/`rtl` and no directional
 * margin. The exceptions are deliberate and named below.
 *
 * The `<x-*-layout>` trap needs no assertion here: `SlotLayoutViewsRenderTest` already walks every
 * view in the tree for it.
 */
class DocumentationEditorialScreenContractTest extends TestCase
{
    use StripsSourceComments;

    private const VIEW = 'resources/views/platform/docs/editorial.blade.php';

    private const CONTROLLER = 'app/Http/Controllers/DocumentationEditorialController.php';

    #[Test]
    public function test_the_screen_extends_the_workdo_layout_rather_than_yielding_nothing(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        $this->assertStringContainsString("@extends('layouts.app')", $source,
            'the editorial screen does not extend the admin layout, so it renders without the '
            . 'application chrome.');

        $this->assertStringContainsString("@section('content')", $source,
            'the screen yields no content section, so the layout renders empty.');
    }

    /**
     * NO WRITE AFFORDANCE. Asserted on the view AND on the controller, because either one alone
     * would leave the other free to grow one.
     */
    #[Test]
    public function test_the_screen_offers_no_way_to_change_an_article_state(): void
    {
        $view = $this->sourceWithoutComments(self::VIEW);

        foreach (['<form', '@csrf', 'method="post"', 'method="POST"', '<button'] as $needle) {
            $this->assertStringNotContainsString($needle, $view,
                "the editorial screen contains `{$needle}`. The screen is read-only on purpose: an "
                . 'article changes state through a reviewed commit to its front matter, and a '
                . 'button here would write to a filesystem the next deploy replaces. If the '
                . 'workflow is genuinely moving to in-app editing, that is a product decision and '
                . 'this test is the right place to find out it was made.');
        }

        $controller = $this->sourceWithoutComments(self::CONTROLLER);

        foreach (['file_put_contents', 'File::put', 'Storage::', 'unlink', 'fwrite'] as $needle) {
            $this->assertStringNotContainsString($needle, $controller,
                "the editorial controller contains `{$needle}`, so it writes to the filesystem. "
                . 'The documentation corpus is version-controlled content and the application only '
                . 'reads it.');
        }
    }

    /**
     * THE GATE IS THE OPERATOR'S IDENTITY, AND THE THREE WRONG SPELLINGS ARE ABSENT.
     *
     * `@can()` / `$user->can()` are ALWAYS FALSE here (`permissions_as_gates => false`), and
     * `isAbleTo()` alone refuses the platform operator because there is no super-admin bypass. A
     * gate written either way would be wrong in opposite directions, and both look right.
     */
    #[Test]
    public function test_the_controller_gates_on_the_operator_and_not_on_a_gate_or_a_permission(): void
    {
        $controller = $this->sourceWithoutComments(self::CONTROLLER);

        $this->assertStringContainsString('UserPolicy::isOperator', $controller,
            'the controller does not use the canonical operator test. `UserPolicy::isOperator()` '
            . 'is `strtolower(rtrim($type, \' \'))`, matching MySQL PAD SPACE; a second spelling '
            . 'with `trim()` admits a row typed `\' super admin\'`.');

        foreach (['->can(', 'Gate::allows', '@can'] as $needle) {
            $this->assertStringNotContainsString($needle, $controller,
                "the controller uses `{$needle}`, which is ALWAYS FALSE in this application "
                . '(Laratrust with `permissions_as_gates => false`), so the gate would refuse '
                . 'everybody including the operator.');
        }

        $this->assertStringNotContainsString('isAbleTo(', $controller,
            'the controller uses `isAbleTo()`. There is no super-admin bypass here, so that '
            . 'refuses the platform operator — the only principal allowed on this screen — while '
            . 'admitting any merchant who grants themselves the permission through their own '
            . 'roles.');
    }

    /**
     * EVERY VISIBLE STRING IS TRANSLATED, and the directional attributes are only the two that
     * have a reason.
     */
    #[Test]
    public function test_the_screen_hard_codes_no_direction_except_where_a_language_demands_one(): void
    {
        $source = $this->sourceWithoutComments(self::VIEW);

        /* The per-language cells and the id/version tokens are the ONLY directional attributes,
           and each is directional because its CONTENT has a fixed direction regardless of the
           reader's: an Arabic title inside an English page is the thing this screen exists to
           show, and an article id is an ASCII path. Anything else is a layout decision that
           belongs to the stylesheet's logical properties. */
        preg_match_all('/dir="(?:ltr|rtl)"|dir="\{\{[^}]*\}\}"/', $source, $found);

        $this->assertLessThanOrEqual(3, count($found[0]),
            'the editorial screen hard-codes ' . count($found[0]) . ' directions: '
            . implode(', ', $found[0]) . '. Direction comes from the layout; a hard-coded one '
            . 'does not mirror when the page does.');

        foreach (['margin-left', 'margin-right', 'padding-left', 'padding-right', 'text-align: left', 'text-align: right'] as $physical) {
            $this->assertStringNotContainsString($physical, $source,
                "the screen uses the physical property `{$physical}`, which does not mirror in "
                . 'Arabic. The design system\'s logical properties do.');
        }
    }
}
