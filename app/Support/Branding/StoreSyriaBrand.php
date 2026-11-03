<?php

namespace App\Support\Branding;

/**
 * The Store Syria brand, measured from the logo the owner supplied on 2026-11-03.
 * =================================================================================================
 *
 * ONE SOURCE FOR THREE CONSUMERS. The admin layout reads it to colour the host chrome, the design
 * system's `--ss-blue-*` ramp is built on the same anchors, and the Flutter admin mirrors them.
 * Before this class each of those carried its own literal and they had drifted into three different
 * brands; a constant that all three cite is what stops that happening again.
 *
 * THE NUMBERS ARE MEASURED, NOT CHOSEN. The authoritative artwork is committed at
 * storage/app/public/uploads/19/48_1787227959_Logo-01 (1).png (1080x1080, the full wordmark).
 * Sampling its 30,235 chromatic pixels — alpha > 200, saturation > 0.25, value > 0.15 — gives:
 *
 *     hue span   189.4° .. 218.9°, in two dense bands
 *     #335DA8    hue 218.5°, the single most common chromatic colour at 3,713 pixels
 *     #0EAED1    hue 190.8°, the centre of the cyan band; 27% of pixels sit within 3° of it
 *
 * Both reproduce the owner's own sampled values, which is the cross-check that matters: the file
 * in the repository and the file on the owner's desk are the same artwork.
 *
 * WHAT THIS REPLACED, and why it was wrong rather than merely different. The Sprint 01 ramp sat at
 * hue 172° and its README said, honestly, that it came from the logo — citing
 * storage/app/public/uploads/2/*logoStore.svg. That path is an upload under STORE ID 2: a tenant's
 * own file. Its embedded artwork is a teal-green recolour of this same mark (measured 167.2–197.5°,
 * median 177.3°). The derivation was sound; the source was a tenant's copy, not the platform's.
 *
 * NOT A LOGO CHANGE. Nothing here alters, regenerates or replaces the artwork. These are readings
 * taken from it.
 */
final class StoreSyriaBrand
{
    /** The logo's dominant blue. 6.42:1 on white, so it can carry a white label. */
    public const BLUE = '#335da8';

    /** The logo's cyan. 2.63:1 on white — an accent and a dark-mode brand, never light-mode text. */
    public const CYAN = '#0eaed1';

    /**
     * What the merchant admin's chrome falls back to when a store has chosen no colour of its own.
     *
     * The host paints its chrome from one of ten `theme-N` body classes or, when `color_flag` is
     * set, from `custom-color` plus a hex in `--color-customColor`. The platform default used to be
     * `theme-3` (#10B77F, a green at hue 160°) which is 29.5° outside the logo's gradient, so every
     * store that never picked a colour wore a brand the logo does not contain.
     *
     * This does NOT override a store that HAS picked one: white-labelling is a product feature and
     * `$setting['color']` still wins. It changes only the fallback.
     */
    public const CHROME_DEFAULT = self::BLUE;

    /** The hue band the artwork actually spans, for anything that needs to test membership. */
    public const HUE_MIN = 189.4;
    public const HUE_MAX = 218.9;

    /** True when a hue in degrees lies inside the logo's own gradient. */
    public static function hueIsInBand(float $hue): bool
    {
        return $hue >= self::HUE_MIN && $hue <= self::HUE_MAX;
    }
}
