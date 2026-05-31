# Narratic — Services & Workflows (Phase 2)

**Purpose**: What each workflow must achieve and the rules it enforces — the end results, not the mechanics. How you batch API calls, diff rating rows, queue work, or schedule runs is an implementation choice.

**Two separations to hold throughout:**
- **Ingestion records facts and emits events. It never sends email.** Notifications are produced by listeners and delivered by digests.
- **Scheduling cadence is configuration.** Suggested intervals are starting points only.

---

## 1. The spine: append-only facts

Ingestion never sends email and never writes per-member notification state. It writes **append-only facts**; digests (§8) read those facts at send time and apply each member's current preferences. The facts:

| Fact | Written by | Table |
|---|---|---|
| Rating snapshot (only on change) | Ratings sync (§3) | `rating_snapshots` |
| Review row | Review ingestion (§4) | `reviews` |
| Availability transition | Availability check (§7) | `availability_events` |
| Auto-tracking | Autotracking (§5) | `trackings` (`source = auto`) |

Each step *may* also emit a domain event (`RatingsChanged`, `ReviewPublished`, `BecameAvailable` / `BecameUnavailable`, `NewReleaseAutoTracked`) for future consumers such as a real-time UI or audit logging. **Digests do not depend on these events** — they query the fact tables directly. There is no notification queue and no per-member fan-out on ingest.

---

## 2. Catalog Search & Audiobook Ingestion

**Goal:** turn an Audible search into audiobook records.

- Search Audible by one of `author | narrator | title` (client contract in `03-external-integrations.md`).
- Upsert each result keyed on `(asin, region)` — existing rows updated, new ones created.
- Ensure `contributors` exist (matched by `slug`) and attach with the correct `role`.
- Record `cover_image_url`.

**Rules**
- Identity is `(asin, region)`; never create a second row for the same pair.
- User-triggered (search UI) and reused by autotracking (§5).

---

## 3. Ratings Sync

**Goal:** keep each tracked title's rating history current and emit `RatingsChanged` when it moves.

**End result:**
- For every audiobook due for a refresh, compare the source's current aggregate ratings against the latest stored snapshot.
- If anything changed, append exactly one new snapshot (with its three aspect rows) and emit `RatingsChanged`.
- If `num_reviews` rose, set `reviews_pending = true` (this drives review ingestion in §4).
- Stamp `ratings_synced_at`.

**Rules / guards:**
- **Zeroed-rating guard:** if the source returns a zeroed distribution where a positive one existed, treat it as an error — increment `rating_zeroed_count`, skip the update, write no snapshot. Resume on the next valid response.
- **Review count may decrease** (a review was removed). Allowed; record it, don't assume monotonic growth.
- **Pre-orders are skipped** — a future `published_at` means no ratings exist yet.

**Title selection** — *which* titles are due for a refresh, and how often, is an adaptive freshness heuristic (older/quieter data checked less often) decided in **`05-adr-ratings-sync-cadence.md`**. Summary: a daily ceiling, backing off to a 5-day floor by recency-of-change, materialized as `next_check_at` and drained through a rate-limited queue. How ASINs are batched per region and how diffs are computed/stored remain free implementation choices; none changes the result above.

---

## 4. Review Ingestion

**Goal:** fetch and store new reviews for titles flagged `reviews_pending`, and emit `ReviewPublished`.

**End result:**
- For each audiobook with `reviews_pending = true`, fetch recent reviews from the source.
- Insert reviews not already stored (dedupe on `(source, external_id)`).
- Parse by format: freeform → `body`; guided → `guided_responses` (structured). Preserve `submitted_at`.
- Emit `ReviewPublished` per new review.
- Clear `reviews_pending` afterward regardless of fetch success (a failed fetch retries on the next ratings-driven flag, not in a tight loop).

**Rules**
- **Audiofile reviews** come from a different source and are matched by normalized title slug (known weak join — log unmatched ones). **This source is deferred post-beta** (see `03-external-integrations.md` §2); the `source = audiofile` column is reserved in the schema.
- Notification eligibility (age gate, thresholds, toggles) is decided in §6, not here.

---

## 5. Autotracking

**Goal:** auto-track new releases matching members' saved searches.

**End result:**
- For each `AutotrackRule`, search Audible by its `search_type`/`term`.
- Keep only genuinely new releases: `published_at` within the last 7 days (configurable) and not already tracked by that member.
- Ingest the audiobook (§2), create a `Tracking` with `source = auto`, and emit `NewReleaseAutoTracked`.

**Rules**
- **Respect the tracking cap.** Before creating the tracking, check `Membership.maxTracked(user)` against the member's current tracking count. If at the cap, skip and log — do not create the tracking.
- A member never gets two trackings for the same title; if already tracked manually, autotrack is a no-op.

---

## 6. Digest selection rules

"Should this member be told about this?" is decided **at digest time** (§8), per member, by querying the fact tables within the member's window `(last_digest_at, now]` and applying their *current* preferences. These are the filters; the assembly is §8.

**Common gates (every section):**
- The member must **currently** track the subject audiobook (join `trackings`).
- The member's subscription must be **active** (`Membership.isActive`). If inactive, they receive nothing this run, and tracking is left intact — they resume automatically on renewal. Because this is checked at send time, a lapse-then-renew leaves no stale items.

**New reviews** — include a review when:
- `review_notifications_enabled` is true, **and**
- its `created_at` is in the window (so late-fetched reviews are never missed), **and**
- all three aspects meet the member's current minimums (`overall ≥ min_overall AND story ≥ min_story AND performance ≥ min_performance`), **and**
- it is fresh: `submitted_at` within the last 30 days — **except** Audiofile reviews, which bypass the age gate (treated as authoritative). *(Audiofile source is deferred post-beta; rule stands for when it ships.)*

> This is a stricter rule than on-site *visibility* (Phase 1 §4): visibility uses story/performance only; digest inclusion additionally requires `overall ≥ min` plus freshness. Both are evaluated dynamically against current thresholds — neither is frozen at ingest.

**Rating changes** — include when `ratings_notifications_enabled` is true and a snapshot was `recorded_at` in the window. Report the **net delta** over the window (compare the latest snapshot to the one in effect at `last_digest_at`), not each intermediate snapshot. Any aspect moving is enough; there is no per-aspect toggle.

**Availability changes** — include `availability_events` with `occurred_at` in the window for tracked titles. Not gated by the review/rating toggles. (Collapse a flap to its net effect if both directions occur in one window.)

**New releases** — include `trackings` with `source = auto` and `created_at` in the window (the titles autotracking added for this member since last digest).

---

## 7. Availability Checking

**Goal:** detect when tracked titles disappear from / return to the catalog, and drive notifications and decay.

**End result:**
- For audiobooks due a check (not checked in ~7 days, configurable), probe availability.
  - **Title selection** follows the same materialized-timestamp / claimer-tick pattern as Ratings Sync (§3). See ADR 06 (`06-adr-availability-checking-pattern.md`).
- On failure: increment `unavailable_strikes`. On reaching the strike threshold (2), set `availability = unavailable`, set `unavailable_since` if not already set, **append an `availability_events` row** (`available → unavailable`), and optionally emit `BecameUnavailable`.
- On success after being unavailable: reset `unavailable_strikes`, clear `unavailable_since`, set `availability = available`, **append an `availability_events` row** (`unavailable → available`), and optionally emit `BecameAvailable`.
- Stamp `availability_checked_at`.

**Rules**
- The strike threshold absorbs transient HTTP errors — a single failure must not flip state. Be conservative about declaring unavailability.
- **Always append the event when (and only when) the resolved state actually changes.** The `availability_events` log is the only durable record of a transition; `unavailable_since` is cleared on recovery, so without the log a flap within a digest window would be invisible (Phase 1 §2.8).
- **180-day decay:** when `unavailable_since` is older than 180 days, remove all of that audiobook's `trackings` (Phase 1 §3 invariant 7).

---

## 8. Digests (delivery)

**Goal:** assemble and send each member's digest by querying the fact tables — no queue.

**End result:** on the relevant schedule (the daily run handles `digest_frequency = daily` members; the weekly run handles `weekly`), for each eligible member:
1. Capture `now`. Resolve the window start: `last_digest_at`, or a configured initial lookback if null (Phase 1 §5).
2. Build the three sections by applying §6's rules over `(window_start, now]`:
   - **New releases & availability** — auto-trackings and `availability_events` in the window.
   - **Rating changes** — net deltas for snapshots in the window.
   - **New reviews** — reviews in the window passing the member's current thresholds + freshness.
3. If every section is empty, send nothing and still advance the cursor (no empty emails).
4. Otherwise render and send via the `Mailer` contract (Phase 3).
5. On a successful send, set `last_digest_at = now`.

**Rules**
- **The cursor is the idempotency guard.** Advance it only after a successful send; a crash beforehand re-sends the same window next run (a rare duplicate, never a loss).
- All preference checks (toggles, thresholds, active subscription) are applied here against current values — there is nothing pre-filtered to trust.
- A member is on exactly one cadence (`digest_frequency`); `off` skips them entirely. Send time per cadence is configuration.
- Delivery audit, if ever needed, is the mail provider's transactional log — not a local table.

---

## 9. Suggested scheduling (non-binding)

Cadence is configuration. Reasonable starting points, expressed as scheduled jobs feeding queues:

| Workflow | Starting cadence | Notes |
|---|---|---|
| Ratings sync | frequent tick (minutes) | The tick is a **claimer**, not a per-title fetch cadence — `next_check_at` governs actual volume. See `05-adr-ratings-sync-cadence.md`. |
| Review ingestion | frequent (minutes) | Only touches `reviews_pending` titles. |
| Availability check | hourly sweep | Each title actually probed ~weekly. |
| Autotracking | daily | |
| Digests | daily + weekly | Send time configurable. |

The ratings→reviews handoff is a flag (`reviews_pending`) plus an event. With a real queue you may dispatch review ingestion directly when the flag is set instead of polling; both satisfy the spec.
