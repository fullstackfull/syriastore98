{{--
    ONE MEASURE, AND THE CAVEATS THAT QUALIFY IT — GAP-152 / P05-04.

    THIS COMPONENT HAS NO `hint` PROP, AND THAT ABSENCE IS THE WHOLE POINT. `<x-ss.stat>` takes
    `value`, `period` and `hint` as three independent strings, so a screen can render the figure and
    leave the disclosure out, which is exactly what five surfaces did with
    `MetricCaveat::EXCLUDES_PAYMENT_FEES` -- attached unconditionally by `StoreRevenueMargin`,
    translated into Arabic, ratcheted by a test, and rendered nowhere (measured 2026-11-25). Here
    there is nothing to leave out: the value, the period and the sentences all come off the one
    `CaveatedFigure`, whose `hint()` is computed from its caveat list.

    IT ACCEPTS ONLY A CARRIER, and refuses an array with a message rather than rendering a figure
    whose caveats nobody can see. Accepting `['value' => ..., 'hint' => ...]` as well would reopen
    the hole it exists to close -- the hint would be a string somebody composed, and the next
    caveat would be forgotten in the next file.

    IT RENDERS THROUGH `<x-ss.stat>` rather than beside it, so the markup, the visible
    `.ss-stat__hint`, the per-instance `aria-describedby` and the honest `Not available` state are
    the audited ones (P5-11, `StatHintIsVisibleTest`) and not a second implementation of them.

    NOTHING HERE COMPUTES. No figure, period, amount or sentence is produced in this file.
--}}
@props([
    'label',
    'figure',
    'href' => null,
    'comparison' => null,
])

@php
    if (! $figure instanceof \App\Support\Metrics\CaveatedFigure) {
        throw new \InvalidArgumentException(
            '<x-ss.figure> renders a App\Support\Metrics\CaveatedFigure, so that a figure and its '
            . 'caveats cannot be separated on the way to the screen. Received '
            . (is_object($figure) ? get_class($figure) : gettype($figure)) . ' for "' . $label . '". '
            . 'Build the figure through CaveatedFigure::from($result, ...) in the metric layer.'
        );
    }

    /*
     * A SET'S DISCLOSURE IS NOT A TILE. `CaveatedFigure::forFigures()` carries the caveats of
     * SEVERAL figures and has no value of its own, so rendering it here would print "Not available"
     * beside figures the screen is showing a few pixels away. Its `hint()` belongs in a disclosure
     * block.
     */
    if (! $figure->isSingleFigure()) {
        throw new \InvalidArgumentException(
            'The figure passed to <x-ss.figure> for "' . $label . '" is a disclosure for several '
            . 'figures (CaveatedFigure::forFigures), which has no single value to render. Print its '
            . 'hint() in a disclosure block instead.'
        );
    }
@endphp

<x-ss.stat :label="$label"
           :value="$figure->value()"
           :period="$figure->period()"
           :hint="$figure->hint()"
           :href="$href"
           :comparison="$comparison"
           {{ $attributes }} />
