<?php

namespace App\Support\Branding;

use App\Support\Html\RichText;

/**
 * Which values a store may keep as its COLOUR, and how one is printed.  P1-SEC-80, round 3 (finding C-1).
 * =================================================================================================
 *
 * The admin layout prints the store's `color` setting inside a `<style>` block on EVERY panel page (`:root { --color-customColor: ...; }`), and the setting is written by handlers that take every
 * posted name (`SettingController::BusinessSettings`, and the other settings writers the same way) with no rule on the value. A value of `red;}</style><img src=x onerror=...>` closed the block and
 * ran in the page of whoever opened the next panel page: the owner, for a member of staff who may save settings; the operator who impersonates a merchant, for the merchant.
 *
 * Two layers, because the second is the control and the first is the rule: `isValid()` is what the Settings handler asks before it writes anything, and `forCss()` is what every layout asks before it
 * prints, so a value that was stored before the rule existed, or by a writer that does not ask, still cannot leave the declaration it is printed in.
 *
 * What a store may choose is what the Settings screen offers: one of the ten theme names, or a CSS colour (hex, a name, `rgb()`/`rgba()`). A gradient is not one, and the screen never posts one.
 */
final class StoreColour
{
    private const THEME = '/^theme-(?:[1-9]|10)$/';

    public static function isValid(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        return preg_match(self::THEME, $value) === 1 || RichText::cssColor($value) !== null;
    }

    /**
     * The redirect a settings handler returns for a posted `color` or `custom_color` that is not a colour, or null. Asked BEFORE anything is uploaded or written: the handler takes `$request->all()`
     * into `settings`, colour names included. It lives here, and the handler asks it in ONE line, because the file has line-keyed inventories below that line.
     */
    public static function refusalFor(\Illuminate\Http\Request $request): ?\Illuminate\Http\RedirectResponse
    {
        foreach (['color', 'custom_color'] as $name) {
            if ($request->has($name) && ! self::isValid($request->input($name))) {
                return redirect()->back()->with('error', __('The colour is not valid.'));
            }
        }

        return null;
    }

    /** the value as it may be printed inside a CSS declaration: itself when it is a colour a store may choose, otherwise $fallback */
    public static function forCss(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' && self::isValid($value) ? trim($value) : $fallback;
    }
}
