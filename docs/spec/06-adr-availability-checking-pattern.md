# ADR 06 — Availability Checking: Same Claimer Pattern as Ratings Sync

**Status**: Accepted (2026-05-30)
**Scope**: How Availability Checking (`02-services-and-workflows.md` §7) selects which tracked titles to probe, and how often.
**References**: ADR 05 (`05-adr-ratings-sync-cadence.md`) — the same due-selection pattern applies; this ADR is the delta.

---

## Context

Availability Checking probes whether tracked titles are still in the Audible catalog. The spec (§7) says "not checked in ~7 days." That's the same structural shape as Ratings Sync (§3), which now uses a materialized `next_check_at` timestamp claimed by a frequent scheduler tick.

Building a separate, custom selection query for availability would mean:

- A second scope with different date math (`WHERE availability_checked_at < NOW() - INTERVAL 7 DAY`)
- A second index on `availability_checked_at` (already exists, but a range scan vs a point lookup)
- No jitter, so all 7-day-interval titles clump on the same cron run

All of these are solved by reusing the ADR 05 pattern.

---

## Decision

Availability Checking will mirror the Ratings Sync plumbing exactly:

- **Add `next_availability_check_at`** to the `audiobooks` table (alongside `next_check_at`). Same semantics: null = due now, otherwise a concrete future timestamp.
- **Stamp after each probe**: the availability service sets `next_availability_check_at = now + 7 days` (with ±15% jitter, same as ADR 05).
- **Selection scope**: identical to `dueForRatingsSync` but reading `next_availability_check_at` instead of `next_check_at`. Can even share a generic `scopeDueFor(Builder, string $column)` if desired.
- **Scheduler**: a separate `availability:check` command on the same 5-minute tick with `withoutOverlapping`. Or a single claimer job that processes both queues — implementation choice, no spec impact.

### Why not a pure SQL interval on `availability_checked_at`

The same three reasons from ADR 05 §2 apply:

1. **Testability** — stamping a known timestamp is a plain factory test; date math against `NOW()` fights SQLite.
2. **Indexability** — `WHERE next_availability_check_at <= NOW()` is a point lookup on a single column; `WHERE availability_checked_at < NOW() - INTERVAL 7 DAY` is a range scan that widens over time.
3. **Jitter** — intra-week spreading needs a per-title offset, which is a materialized column.

### What's different from Ratings Sync

| Concern | Ratings Sync | Availability Checking |
|---|---|---|
| Interval | Adaptive (1–5 days by `rago`) | Fixed (7 days, configurable) |
| Bands config | `newness_days`, `bands` array | Single `interval_days` value |
| Reset condition | On every sync (always rescheduled) | On every probe (always rescheduled) |

Everything else — claimer tick, materialized timestamp, jitter, scope shape, index strategy — is identical.

---

## Consequences

- **Implementation cost is low.** Add one column, one scope reuse (or trivial copy), one stamp in the availability service. No new architectural decisions.
- **Steady-state load is ~1/7th of the catalog per day**, spread across the week by jitter. No thundering-herd problem.
- **Worst-case staleness is ~7 days** for a quiet, stable title. Configurable.
- **The "claimer tick, not fetch cadence" rule (ADR 05 §3) applies.** The tick runs every 5 minutes; `next_availability_check_at` governs actual API volume.

---

## Configuration

Add to `config/narratic.php` under a new `availability_check` key:

```php
'availability_check' => [
    'interval_days' => (int) env('NARRATIC_AVAILABILITY_INTERVAL_DAYS', 7),
    'jitter_pct'    => (float) env('NARRATIC_AVAILABILITY_JITTER_PCT', 0.15),
    'batch_limit'   => (int) env('NARRATIC_AVAILABILITY_BATCH_LIMIT', 200),
],
```

---

## Cross-reference

Update `02-services-and-workflows.md` §7 to note the implementation pattern, and `04-implementation-status.md` to reflect the decision.
