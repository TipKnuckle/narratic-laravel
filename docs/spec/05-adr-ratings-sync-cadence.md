# ADR 05 — Ratings Sync Cadence & Title Selection

**Status**: Accepted (2026-05-30)
**Scope**: How Ratings Sync (`02-services-and-workflows.md` §3) selects which tracked titles to check, and how often.
**Supersedes**: the initial "check every 15 minutes" implementation.

---

## Context

Ratings Sync must keep each tracked title's rating history current without abusing the data source. Four constraints shape the decision:

1. **The only consumer is the digest**, sent at most **once per day** (some members weekly). A rating change found at 09:00 vs 14:00 is indistinguishable to the recipient — both are "today's data." Polling resolution finer than the delivery cadence buys nothing.
2. **The source is an undocumented, unofficial Audible REST API.** A measured, low-volume request profile is a hard requirement, not a nice-to-have.
3. **Activity decays with age but spikes unpredictably.** A title's ratings/reviews slow as it ages in the marketplace, but a marketing push can revive a long-quiet title at any time.
4. **The data is retrospective, not actionable.** Nobody needs to know within minutes. There is no urgency floor — only an upper bound on staleness we're comfortable with.

The scale: a low-hundreds userbase tracking **thousands of distinct `(asin, region)` titles**. Selection is per-title (deduped across trackers), not per-tracking.

This decision blends three inputs: the heuristic proven over years in the legacy WordPress system, a cleaner single-rule expression of it, and queue-based plumbing native to Laravel.

---

## Decision

### 1. Daily ceiling, age-aware backoff

Each title carries a computed **`next_check_at`**. A title is never checked more than once per day, and quiet older titles back off toward a 5-day floor. The interval is a lookup on **days-since-last-change** — days since the title's ratings *last actually changed* (i.e. days since its latest `rating_snapshots.recorded_at`, since snapshots are written only on change):

| Condition | Interval until next check |
|---|---|
| Never synced (`ratings_synced_at` is null) | **due now** |
| Published within the last 90 days | **1 day** (newness floor — new titles stay hot regardless of change history) |
| Last changed < 30 days ago | **1 day** |
| Last changed 30–59 days ago | **3 days** |
| Last changed 60–119 days ago | **4 days** |
| Last changed ≥ 120 days ago | **5 days** (cold cap) |

This is the legacy throttle matrix flattened into a single interval lookup. The numbers are the legacy's proven values, not new guesses. All thresholds and intervals live in `config/narratic.php` (see below) — they are tuning knobs, not domain rules.

> The legacy WordPress code drove this off two cryptically-named variables, `rago` and `lago` — best reconstructed as "ratings-ago" (days since ratings changed) and "last-ago" (days since last checked). Those names are *not* carried forward; this system uses "days since last change" and `next_check_at`.

**No ratings history at all:** a title past the newness window that has *never* recorded a snapshot (a release that never gained traction) is treated as maximally quiet — it drifts straight to the cold cap, not the daily floor. We don't check a dead listing daily forever. Within the newness window it stays daily like any new release, in case ratings are simply slow to appear.

**Why days-since-*change* and not days-since-last-*check*:** days-since-change measures the real signal — how volatile this title actually is — and is independent of how often we happened to poll. It survives cadence changes, backfills, and gaps cleanly. A title that hasn't moved in a year self-selects into the 5-day tier; the first detected change resets it to daily, so a marketing spike is caught at full resolution from the moment we notice it.

### 2. Materialized `next_check_at`, not derived-on-read

After each sync, compute the interval in PHP and write `next_check_at = now + interval`, **plus per-title jitter** (±15%) so re-checks don't clump. Selection is then a trivial, index-friendly, database-agnostic query:

```
where next_check_at is null or next_check_at <= now()
order by next_check_at asc
limit <batch>
```

`next_check_at` is a **derived cache, not a source of truth** — it is fully recomputable from `published_at` + latest snapshot `recorded_at`. If the config is retuned, rows self-heal on their next sync (a one-off backfill command can force it). We chose this over computing eligibility from raw facts in a SQL `CASE` for three reasons:

- **Testability** — asserting "an unchanged-120-day title gets `next_check_at` 5 days out" is a plain PHP/factory test. The repo runs Pest against SQLite; raw `NOW() - INTERVAL n HOUR` date math is MySQL-flavored and fights that.
- **Indexability** — a single timestamp column indexes cleanly; a computed `CASE` predicate against `NOW()` forces a scan.
- **Jitter has to live somewhere.** Intra-day spreading needs a *stable* per-title offset. There is nowhere to put that in a pure derive-on-read query without inventing a per-row seed column — at which point you've materialized state anyway. The jitter requirement alone settles the question.

### 3. Intra-day load spreading is explicit

The interval lookup spreads checks **across days**. It does **not**, on its own, spread them **within** a day — every title checked at yesterday's 09:00 run becomes due again at today's 09:00 run and lands in one clump. The legacy "~12 pages" mechanism existed precisely to smooth this within-day burst.

We preserve that goal, expressed the Laravel-native way:

- **Jitter on `next_check_at`** (§2) staggers due-times across the 24h window.
- A **frequent scheduler tick** (every few minutes) claims due titles `order by next_check_at asc limit <batch>` and dispatches them to a **rate-limited queue**, which drains at a steady, polite request rate (with backoff + jitter on 429/5xx).
- The `order by next_check_at asc` is **load-bearing**: if the due set ever exceeds a tick's batch (e.g. after downtime), the oldest-overdue titles are served first and nothing starves. Sizing rule: `tick_frequency × batch ≥ daily eligible count`.

### 4. Review detail-fetch stays a separate phase

Cheap ratings polling only detects *that* the review count rose (it sets `reviews_pending`, `02-services-and-workflows.md` §3–4). Fetching the actual review *content* is a separate, independently rate-limited job keyed off that flag. This decoupling — carried forward from the legacy system — keeps the heavy work off the polling path and bounds its rate independently.

---

## What we rejected

- **A 15-minute (or any sub-daily) fixed poll of all titles.** Finer than the delivery cadence, so it buys nothing, and it's bursty against an unofficial API. A frequent *tick* is fine — but only as a **claimer** governed by `next_check_at`, never as a per-title fetch cadence. The original implementation copied the heartbeat and dropped the throttle, which is the entire brain of the design.
- **A 48-hour cold-tier cap.** Tempting for "freshness," but the cold tier is the *bulk of the catalog* (thousands of old, settled titles), so that tier dominates total request volume. Pulling the legacy's 5-day cap in to 2 days more than doubles steady-state load on the unofficial API — a regression against constraint #2, justified only by margin we don't need for retrospective data.
- **A 12-hour "2× daily" hot tier.** A single daily check timed before the send window already captures everything since the last digest. Twice-daily spends extra requests for no deliverable difference and contradicts constraint #2.
- **Deriving eligibility purely from facts in a SQL `CASE` (no stored column).** Elegant on paper, but loses testability and indexability, and has nowhere to anchor jitter — see §2.

---

## Consequences

- **The same plumbing generalizes.** Availability Checking (§7) is the same due-selection shape with a fixed ~7-day interval; it can reuse the claimer + materialized-timestamp pattern (ADR 06).
- **Worst-case staleness is bounded at 5 days** for the quietest titles, well inside even the weekly digest window. A spike on a dormant title is detected within 5 days and then tracked daily.
- **Steady-state load is a trickle.** With most of the catalog settled at the 5-day tier, a few thousand titles resolve to on the order of a few hundred requests/day, drained steadily — not a daily thundering herd.
- **The cadence is assertable.** Each band → interval mapping is a unit test; the claimer's ordering and batch behavior are feature-testable without real HTTP or DB-specific date math.
- **Retuning is config-only.** Thresholds and intervals move in `config/narratic.php`; rows converge on the next sync.
- **The same plumbing generalizes.** Availability checking (§7) is the same due-selection shape with a fixed ~7-day interval; it can reuse the claimer + rate-limited-queue pattern.

---

## Configuration

```php
// config/narratic.php
'ratings_sync' => [
    'newness_days' => 90,   // titles published within this window stay at the daily floor
    'jitter_pct'   => 0.15, // ± spread applied to next_check_at to smooth intra-day load
    'batch_limit'  => 200,  // max titles a single scheduler tick claims

    // days-since-last-change => interval in days until next check.
    // Pick the interval for the largest threshold <= days-since-change.
    'bands' => [
        0   => 1,
        30  => 3,
        60  => 4,
        120 => 5, // cold cap
    ],
],
```

---

## In one line

**Legacy heuristic (daily ceiling, back off by recency-of-change, 5-day cold cap), expressed as one config-driven interval lookup, plumbed as a materialized `next_check_at` + a rate-limited queue that spreads load within the day.**
