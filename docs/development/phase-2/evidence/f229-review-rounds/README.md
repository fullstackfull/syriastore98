# F229 review rounds — committed evidence

`mariadb-roster-3b846065.txt` — the full MariaDB verification roster on commit `3b846065`
(review round 2 of the Paytm purchase intent), run against **MariaDB 10.11.14** over the unix
socket:

```
passed: 34   failed: 0
every MySQL verification script reported all assertions held.
```

**It is committed because F239 was about exactly this.** The previous baseline asserted
"MariaDB 32/32" with no log anywhere, and the surviving artefacts disagreed three ways (roster 32,
`mysql-roster.txt` 28, its README "27", and the gate log had no MariaDB section at all). A count in
prose is not evidence; the run is.

The roster grew 32 → 34 in this session, by the two scripts F229 added:

| script | what it holds that SQLite cannot |
|---|---|
| `verify-plan-coupon-refusal-ordering-on-mysql` | `BINARY \`code\`` is MySQL-only — a **syntax error** on SQLite, so every initiator using that lookup is unreachable from the Feature suite |
| `verify-plan-purchase-intent-on-mysql` | `DECIMAL(16,4)` is enforced (SQLite's affinity returns `449.1` for `449.1000`), `reference` is UNIQUE as a database fact, and **real row contention** |

## The concurrency check in that second script was theatre, twice over

Worth reading the file for, because both faults are ones a reviewer had to find:

1. **The first version was two sequential autocommit UPDATEs.** A's committed before B's began, so
   `$wonByA + $wonByB === 1` was arithmetic over a sequence. Two connections are necessary for a
   race and not sufficient — nothing was ever contended. It now holds A's transaction open, so the
   row is under A's exclusive lock for exactly the window a second callback lands in.
2. **Then the `FOR UPDATE NOWAIT` check caught `Throwable` with no message inspection**, and would
   have reported *"the lock is real"* on a parse failure: `NOWAIT` needs MariaDB ≥ 10.3 and is a
   syntax error below it, as a missing table or a dropped connection would be too.

The load-bearing line is the one that measures time:

```
  ok    B's identical UPDATE blocks on the row lock rather than overtaking it (1.00s)
```

**1.00s is `innodb_lock_wait_timeout = 1` elapsing**, which can only happen if the lock is genuinely
held. In the old version B's statement returned immediately. Dropping `->where('status', 'pending')`
from the loser's claim gives `1 + 1` — one payment, two activations.

And check 5 **passed while the code it reported on had the defect it named**: it demonstrated "a
stranger can neither consume nor spoil an intent" with a predicate `verifyAndConsume()` does not
use, while `recordRefusal()` was unscoped and a stranger could terminally close the owner's pending
intent. It now points `database.default` at the throwaway database and drives the real service, so
there is no transcription left to diverge from the original.

## Reproducing

```bash
MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root MYSQL_PASS= \
  tests/Support/Security/bin/run-mysql-verifications.sh
```

Every script creates and drops its own database and refuses a name without `verify` in it. Never
point it at a shared server.
