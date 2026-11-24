<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ANA-003 — the scheduled analytics export, as an e-mail with the CSV attached.
 *
 * THE BODY STATES WHAT THE FILE IS AND WHEN IT WAS TAKEN, and that is not decoration. A spreadsheet
 * arriving on a timer is read later, out of context, by someone who did not press the button; a
 * figure whose period is only inside the attachment is a figure whose reader does not know its
 * period. Every row of the CSV already carries its own period and disclosure (that is what
 * `FigureCsv` is for), and the body repeats the window and the run time so the message alone is
 * honest.
 *
 * IT CLAIMS NOTHING ABOUT DELIVERY OR COMPLETENESS. The subject says which store and which period;
 * it does not say "your report" in a way that implies the figures are final, because the ledger
 * coverage caveats inside the file say otherwise and the file is the authority.
 *
 * NO QUEUE. `ShouldQueue` is deliberately absent: there is no queue worker guaranteed to be running
 * in this deployment (the project's Phase 7 readiness notes record the same uncertainty about the
 * scheduler itself), and a mail that sits in a queue nobody drains is a report that silently never
 * arrives. The runner sends synchronously and RECORDS the outcome, so a failure is visible on the
 * merchant's own schedule list rather than invisible in a queue.
 */
class ScheduledAnalyticsReport extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $storeName,
        public readonly string $scheduleName,
        public readonly string $periodLabel,
        public readonly string $runAtLabel,
        public readonly string $csv,
        public readonly string $filename,
    ) {}

    public function build(): self
    {
        return $this
            ->subject(__(':store analytics — :period', [
                'store' => $this->storeName,
                'period' => $this->periodLabel,
            ]))
            ->view('emails.scheduled-analytics-report')
            ->with([
                'storeName' => $this->storeName,
                'scheduleName' => $this->scheduleName,
                'periodLabel' => $this->periodLabel,
                'runAtLabel' => $this->runAtLabel,
            ])
            /*
             * `attachData`, not a temporary file. The CSV is already a string in memory (built by
             * the same `FigureCsv` the download uses), and writing it to disk would add a path to
             * clean up and a window in which store figures sit in a world-readable temp directory.
             */
            ->attachData($this->csv, $this->filename, [
                'mime' => 'text/csv; charset=UTF-8',
            ]);
    }
}
