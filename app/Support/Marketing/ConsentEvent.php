<?php

namespace App\Support\Marketing;

/** What happened. Each value is written once, to an append-only log, and never edited. */
enum ConsentEvent: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
    case Suppressed = 'suppressed';
    case SuppressionLifted = 'suppression_lifted';
}
