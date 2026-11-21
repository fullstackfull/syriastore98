<?php

namespace App\Support\Metrics;

/** A metric that yields exactly one figure for a scope and window. */
interface SingleFigureMetric
{
    public function for(MetricScope $scope, MetricWindow $window): MetricResult;
}
