<?php

namespace App\Support\Metrics;

/**
 * A metric that MUST NOT be reduced to one figure.
 *
 * There is exactly one today -- section 3's unpaid orders, where the document is unambiguous:
 * "Three sub-metrics, never one. Collapsing them is how a COD book looks like bad debt." A COD
 * order awaiting collection, a bank transfer awaiting settlement and an order nobody intends to
 * pay for are three different business facts; one number over the three tells a merchant their
 * receivables are at risk when most of it is cash in a courier's hand.
 *
 * Implementing this interface instead of `SingleFigureMetric` means there is no `for()` to call,
 * so the collapse has to be written out deliberately by a caller who can be asked why.
 */
interface MultiFigureMetric
{
    /** @return array<string, MetricResult> keyed by sub-metric */
    public function all(MetricScope $scope, MetricWindow $window): array;
}
