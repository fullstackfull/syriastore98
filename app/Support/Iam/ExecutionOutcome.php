<?php

namespace App\Support\Iam;

/**
 * `IAM-003` — the answer an executor gives: it happened, or it was refused and this is which rule
 * refused.
 *
 * A REASON AND NOT A BOOLEAN, because CLAUDE.md records that a refusal asserted without its reason
 * is satisfied by a crash, by a missing row, or by an unrelated earlier rule — "a 302 from a
 * missing row and a 302 from a gate are the same status and opposite facts". The approvals tests
 * key on `$reason`, so a mutant that changes WHICH rule refuses is caught and not only one that
 * changes whether.
 */
final class ExecutionOutcome
{
    private function __construct(
        public readonly bool $performed,
        public readonly string $reason,
        public readonly array $detail,
    ) {
    }

    /** @param array<string,mixed> $detail what changed, for the person reading the row later */
    public static function performed(array $detail = []): self
    {
        return new self(true, '', $detail);
    }

    /** @param non-empty-string $reason a stable machine-readable key, not a sentence */
    public static function refused(string $reason, array $detail = []): self
    {
        return new self(false, $reason, $detail);
    }
}
