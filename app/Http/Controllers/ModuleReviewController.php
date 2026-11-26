<?php

namespace App\Http\Controllers;

use App\Models\ModuleReviewRecord;
use App\Support\Modules\ModuleTrust;
use Illuminate\Http\Request;

/**
 * MARKET-002 — WHERE A PLATFORM OPERATOR RECORDS A REVIEW DECISION ABOUT AN UPLOADED PACKAGE.
 *
 * WHY THIS EXISTS AT ALL, stated plainly: `ModuleTrust` stops an unreviewed upload from executing,
 * and without a way to record an approval that would turn the install door into a dead end. A
 * boundary with no gate in it is not a boundary, it is a removed feature — and removing the upload
 * capability is not what `MARKET-004` asks for. It asks for untrusted code not to RUN.
 *
 * WHAT THIS IS NOT, AND THE LIMIT IS THE POINT. `MARKET-002` reads
 * "مراجعات أمنية وتوافق وإصدارات وصلاحيات" — security, compatibility, versioning and permission
 * reviews. This records the OUTCOME of such a review and shows the reviewer the inputs the package
 * declares about itself (`App\Support\Modules\PackageDeclaration`). It does not DECIDE anything: no
 * static analyser runs, no signature is checked, no version policy is applied, and nothing here
 * asserts that an approved package is safe. **The review standard — what an operator must satisfy
 * themselves of before approving — is an owner decision and is deliberately not invented here.**
 * `docs/development/phase-6/PHASE-06-OWNER-DECISION-PACKAGE.md:589` already records that MARKET-002
 * "needs a review standard".
 *
 * THE AUTHORIZATION IS A GATE, NOT `isAbleTo()`, and that is not a style choice. CLAUDE.md §1:
 * Laratrust runs with `permissions_as_gates => false` and `isAbleTo()` has no operator bypass, so a
 * permission-name check REFUSES the platform operator on any database where the name was not seeded
 * for their role. Approving third-party code is a platform-operator action with no per-model
 * subject, so it uses the same `manage-platform-addons` Gate that install, theme-install and
 * theme-enable already use — `$user->type === UserPolicy::PLATFORM_ADMIN`, a string comparison that
 * cannot lock the operator out. The route group carries it AND every method asserts it, because
 * CLAUDE.md records that a handler-level check behind an `abort()`ing middleware is invisible to an
 * HTTP test, and SEC-F2 records what happens when only the grouping is trusted.
 */
class ModuleReviewController extends Controller
{
    /** The uploads awaiting or carrying a decision, newest first. */
    public function index()
    {
        $this->authorize('manage-platform-addons');

        $records = ModuleReviewRecord::query()
            ->orderByRaw("CASE WHEN review_state = '" . ModuleReviewRecord::STATE_PENDING . "' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        return view('module.review', compact('records'));
    }

    /**
     * Record a decision about one uploaded package.
     *
     * THE SUBJECT IS NEVER TAKEN AS A FREE STRING. `subject_type` is validated against the two
     * constants and `subject` must already HAVE a row — `firstOrFail` on the pair, not
     * `updateOrInsert`. A handler that would create a record from request input could be used to
     * pre-approve a package before uploading it, which is the one shape that would defeat the whole
     * control: `ModuleTrust::recordUpload()` resets the state to `pending` on every upload, so an
     * approval can only ever be recorded about bytes that are already on disk.
     */
    public function decide(Request $request)
    {
        $this->authorize('manage-platform-addons');

        $validated = $request->validate([
            'subject_type' => 'required|string|in:' . ModuleReviewRecord::TYPE_MODULE . ',' . ModuleReviewRecord::TYPE_THEME,
            'subject' => 'required|string|max:64',
            'decision' => 'required|string|in:' . implode(',', ModuleReviewRecord::decidableStates()),
            'note' => 'nullable|string|max:255',
        ]);

        $record = ModuleReviewRecord::query()
            ->where('subject_type', $validated['subject_type'])
            ->where('subject', $validated['subject'])
            ->first();

        if ($record === null) {
            return redirect()->back()->with('error', __('There is no uploaded package by that name in the review register.'));
        }

        $record->review_state = $validated['decision'];
        $record->reviewed_by = auth()->id();
        $record->reviewed_at = now();
        $record->review_note = $validated['note'] ?? null;
        $record->save();

        /*
         * A MODULE THAT HAS JUST BEEN APPROVED IS STILL NOT LOADED IN THIS PROCESS, and saying so
         * is more useful than pretending otherwise. `PackageServiceProvider::loadDynamicAddons()`
         * runs inside `app->booted()`, so the provider set for THIS request was decided before the
         * decision existed. The module loads on the next request, which is also when its migrations
         * become visible to `php artisan migrate`.
         */
        return redirect()->back()->with(
            'success',
            $validated['decision'] === ModuleReviewRecord::STATE_APPROVED
                ? __('Review recorded. The package loads from the next request; run your migrations after that.')
                : __('Review recorded. The package stays switched off and its code is not loaded.')
        );
    }

    /**
     * The declarations the package makes about itself, for the reviewer to read.
     *
     * Read STATICALLY at upload time and stored on the row, so this screen never touches the
     * package's files and a package cannot change what the reviewer sees after the fact.
     */
    public function show(string $subjectType, string $subject)
    {
        $this->authorize('manage-platform-addons');

        if (! in_array($subjectType, [ModuleReviewRecord::TYPE_MODULE, ModuleReviewRecord::TYPE_THEME], true)) {
            return redirect()->route('module.review.index')->with('error', __('Unknown package type.'));
        }

        $record = ModuleReviewRecord::query()
            ->where('subject_type', $subjectType)
            ->where('subject', $subject)
            ->first();

        if ($record === null) {
            return redirect()->route('module.review.index')->with('error', __('There is no uploaded package by that name in the review register.'));
        }

        $executable = ModuleTrust::mayExecute($record->subject, $record->subject_type);

        return view('module.review-detail', compact('record', 'executable'));
    }
}
