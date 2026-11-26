<?php

namespace App\Support\Docs;

/**
 * GAP-107 (P03-D-04) — THE EDITORIAL STATE OF A DOCUMENTATION ARTICLE.
 * =================================================================================================
 *
 * The gap: "working documentation exists; an editorial workflow does not". Measured at HEAD —
 * `DocumentationLibrary` parsed `title`, `summary`, `order`, `related` and `updated`, there was no
 * author, owner, reviewer or status field of any kind, and every file in `resources/docs/` was
 * public the moment it was committed. A half-written Arabic translation could not be put in front
 * of a reviewer without being published to every visitor of storesyria.com first.
 *
 * THREE STATES AND ONE RULE. `draft` -> `review` -> `published`, and only `published` is public.
 * The written process that moves an article between them -- who drafts, who reviews the Arabic, who
 * approves, how the change reaches production -- is
 * `docs/development/phase-3/PHASE-03-DOCS-EDITORIAL-WORKFLOW.md`. This class is only the
 * vocabulary; `DocumentationLibrary` is what enforces it, and nothing else in the application may
 * decide what "published" means.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE STATE LIVES IN FRONT MATTER AND NOT IN A DATABASE TABLE, which is the first question
 * anybody asks of a workflow:
 *
 *   - The CONTENT is Markdown in the repository, for the reasons `DocumentationLibrary`'s own
 *     docblock sets out (reviewable in a diff, no production seeder, a bilingual pair visible as
 *     two files). A state in a table and prose in a file is two sources of truth that disagree the
 *     first time somebody reverts a commit.
 *   - The transition IS the reviewed commit. An article reaches `published` when a human with the
 *     authority to approve it merges that one-line change, which is a record with an author, a
 *     timestamp, a diff and a reviewer already — none of which a status column has.
 *   - A web form that rewrote `resources/docs/*.md` would write to a container filesystem that is
 *     replaced on the next deploy, and the edit would be lost with no trace. That is a worse
 *     workflow than none, not a better one.
 *
 * So there is no migration and nothing to keep in step between environments. What the application
 * adds is the ENFORCEMENT (a draft is not served) and the OBSERVABILITY (the operator's editorial
 * screen lists every article's state and can render a draft for review).
 *
 * -------------------------------------------------------------------------------------------------
 * THE TWO DEFAULTS ARE DIFFERENT AND THE ASYMMETRY IS DELIBERATE.
 *
 *   `WHEN_UNDECLARED = published`. A file that declares no status at all PREDATES this field. The
 *   corpus was 32 published articles before it existed, and a field whose introduction removes 32
 *   working help pages from production cannot be introduced. `PlatformDocsBilingualTest` also
 *   writes a fixture article with no status and asserts HTTP 200, so the legacy default is
 *   load-bearing rather than merely convenient.
 *
 *   `WHEN_UNRECOGNISED = draft`. A file that declares `status: publishd` plainly meant to say
 *   something and we cannot tell what. The only safe reading of an unintelligible publication state
 *   is "not approved yet".
 *
 * NEITHER DEFAULT IS A LOOPHOLE, because `DocumentationEditorialStatusTest` requires every shipped
 * article file to declare an explicit, recognised status in both locales, with the two agreeing —
 * and it runs in the Unit suite, which is a CI gate. The runtime is lenient in the direction that
 * keeps readers served; CI is strict in the direction that keeps the workflow real.
 */
final class DocumentationStatus
{
    /** Being written. Not public, not yet offered for review. */
    public const DRAFT = 'draft';

    /** Finished and waiting for the approval the process names. Not public. */
    public const REVIEW = 'review';

    /** Approved. The only state a reader can reach. */
    public const PUBLISHED = 'published';

    /** @var list<string> In workflow order, which is the order the editorial screen groups by. */
    public const ALL = [self::DRAFT, self::REVIEW, self::PUBLISHED];

    /** A file with no `status` key predates the workflow — see the class docblock. */
    public const WHEN_UNDECLARED = self::PUBLISHED;

    /** A `status` nobody can read is an unknown intention, not an approval. */
    public const WHEN_UNRECOGNISED = self::DRAFT;

    /**
     * Spellings a human writing front matter will reasonably use.
     *
     * `in review` is here because that is what the process document and the screen both call the
     * middle state in English, and somebody will type what they read.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'in review'     => self::REVIEW,
        'under review'  => self::REVIEW,
        'reviewing'     => self::REVIEW,
        'needs review'  => self::REVIEW,
    ];

    public static function isDeclared(array $meta): bool
    {
        return array_key_exists('status', $meta);
    }

    /**
     * The state of an article, from its front matter.
     *
     * Takes the whole metadata array rather than a string so that "no key" and "an empty value"
     * cannot collapse into the same argument — they have different defaults.
     */
    public static function of(array $meta): string
    {
        if (! self::isDeclared($meta)) {
            return self::WHEN_UNDECLARED;
        }

        return self::fromWritten((string) $meta['status']) ?? self::WHEN_UNRECOGNISED;
    }

    /**
     * TRUE when a written value reaches its state through the vocabulary rather than the fallback.
     *
     * `of()` answers a state for anything, so a test asserting that it returned a known state
     * passes for every typo. This is the question a census actually needs to ask.
     */
    public static function isRecognised(string $written): bool
    {
        return self::fromWritten($written) !== null;
    }

    /** The one rule. Anything this class does not recognise is NOT public. */
    public static function isPublic(string $status): bool
    {
        return $status === self::PUBLISHED;
    }

    /**
     * The translation key for a state, for the editorial screen and the draft notice.
     *
     * The keys are returned rather than translated so that a caller passes them through `__()`
     * where the locale is the reader's. "Published" already exists in both language files.
     */
    public static function label(string $status): string
    {
        return match ($status) {
            self::PUBLISHED => 'Published',
            self::REVIEW    => 'In review',
            self::DRAFT     => 'Draft',
            default         => 'Unknown',
        };
    }

    /**
     * The design-system badge modifier for a state (`store-syria-ds.css:1459-1463`).
     *
     * Deliberately NOT `danger` for a draft: a draft is the normal first state of every article,
     * not an error, and colouring it red would teach the reader of the screen to ignore red.
     */
    public static function badgeModifier(string $status): string
    {
        return match ($status) {
            self::PUBLISHED => 'ss-badge--success',
            self::REVIEW    => 'ss-badge--warning',
            default         => 'ss-badge--info',
        };
    }

    /** The canonical state a written value names, or null when it names none. */
    private static function fromWritten(string $written): ?string
    {
        /* Lowercased, and hyphens/underscores/runs of whitespace folded to one space, so
           `In-Review`, `in_review` and `  IN   REVIEW ` are the same written value. Front matter is
           hand-typed prose; the alternative is a census that fails on a capital letter. */
        $key = strtolower(trim($written));
        $key = (string) preg_replace('/[-_\s]+/u', ' ', $key);

        if (in_array($key, self::ALL, true)) {
            return $key;
        }

        return self::ALIASES[$key] ?? null;
    }
}
