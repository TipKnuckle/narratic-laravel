# Narratic — Domain Model (Phase 1)

**Purpose**: The relational data model. Every entity maps to a table/migration and a model. Types are generic; Laravel column types are noted where useful.

---

## 1. Entity Map

```
Plan 1───* Subscription *───1 User
                                 │
User *───────* Audiobook         │ (trackings: the core relationship)
User 1───────* AutotrackRule

Audiobook 1──* Review
Audiobook 1──* RatingSnapshot
Audiobook 1──* AvailabilityEvent
Audiobook *──* Contributor        (role: author | narrator)
```

Two things are deliberately *not* stored:
- Per-member, per-review **visibility** is computed at query time (see §4).
- There is **no notification queue.** Digests are derived at send time by querying append-only sources (reviews, rating snapshots, availability events, auto-trackings) against a per-member cursor, applying the member's *current* preferences (see §5 and `02-services-and-workflows.md`).

---

## 2. Entities

### 2.1 Audiobook

The central entity. One row per `(asin, region)`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `asin` | string(12) | Audible product id. Not unique on its own. |
| `region` | enum(`US`,`UK`) | Marketplace; determines which Audible endpoint to query. |
| `title` | string | |
| `subtitle` | string nullable | |
| `description` | text | Publisher summary. |
| `runtime_minutes` | int nullable | |
| `cover_image_url` | string nullable | Canonical cover (≈500px). |
| `published_at` | datetime nullable | Release date; may be in the future (pre-orders). |
| `ratings_synced_at` | datetime nullable | Last successful ratings refresh; drives sync scheduling. |
| `reviews_pending` | boolean default false | Set when a ratings sync detects the review count rose; consumed and cleared by review ingestion. |
| `availability` | enum(`available`,`unavailable`) default `available` | Resolved state. |
| `unavailable_since` | datetime nullable | First confirmed-unavailable time; null when available. |
| `unavailable_strikes` | int default 0 | Consecutive failed availability checks. |
| `availability_checked_at` | datetime nullable | Last availability check; throttles checks. |
| `rating_zeroed_count` | int default 0 | Consecutive zeroed rating responses (error guard); reset on a valid response. |
| `created_at` / `updated_at` | timestamps | |

**Constraints**
- `UNIQUE (asin, region)` — the composite natural key.
- Index `(ratings_synced_at)` and `(availability_checked_at)` for scheduling queries.
- Index `(reviews_pending)` (partial / where-true if supported).

**Notes**
- A future `published_at` *is* the pre-order signal; pre-orders are excluded from ratings sync (no ratings exist yet).
- The cover is a URL. Caching the binary locally is a storage concern, not a domain field.

---

### 2.2 Contributor & audiobook_contributor

Authors and narrators are people entities. The same person can be an author and a narrator, and play different roles on different books.

**`contributors`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | |
| `slug` | string | Normalized for matching/search. Index. |
| timestamps | | |

**`audiobook_contributor`** (pivot)

| Column | Type | Notes |
|---|---|---|
| `audiobook_id` | FK | |
| `contributor_id` | FK | |
| `role` | enum(`author`,`narrator`) | |

- `UNIQUE (audiobook_id, contributor_id, role)`.
- Autotracking and search target contributors by `slug`.

---

### 2.3 Review

One row per customer or Audiofile review; belongs to an audiobook.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `audiobook_id` | FK | |
| `external_id` | string | Source review id; dedup key. |
| `source` | enum(`audible`,`audiofile`) | |
| `format` | enum(`freeform`,`guided`) nullable | Audible only. |
| `author_name` | string | Reviewer display name (Audiofile: initials + year). |
| `title` | string nullable | Review headline. |
| `body` | text nullable | Freeform text; null for guided. |
| `guided_responses` | json nullable | Array of `{question, answer}` for guided reviews. Stored structured; rendered at display time. |
| `rating_overall` | tinyint nullable | 1–5. |
| `rating_story` | tinyint nullable | 1–5. |
| `rating_performance` | tinyint nullable | 1–5. |
| `related_url` | string nullable | Full-review link (Audiofile only). |
| `submitted_at` | datetime | Original submission date from the source. |
| timestamps | | |

**Constraints**
- `UNIQUE (source, external_id)` — dedup.
- Index `(audiobook_id, submitted_at)`.

**Notes**
- Reviews are plain data rows — no comments, permalinks, or status. Per-member visibility is not stored here (see §4).

---

### 2.4 RatingSnapshot (+ aspect distribution)

Append-only time series of an audiobook's aggregate ratings. A snapshot is written only when the numbers change.

**`rating_snapshots`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `audiobook_id` | FK | |
| `recorded_at` | datetime | When captured. |
| `num_reviews` | int | Total written-review count from the source. |
| timestamps | | |

Each snapshot has three aspect rows (`overall`, `story`, `performance`):

**`rating_aspect_snapshots`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `rating_snapshot_id` | FK | |
| `aspect` | enum(`overall`,`story`,`performance`) | |
| `average` | decimal(4,3) | |
| `count` | int | Ratings for this aspect. |
| `star_1` … `star_5` | int | Distribution. |

- `UNIQUE (rating_snapshot_id, aspect)`.
- Index `rating_snapshots(audiobook_id, recorded_at)`.
- Acceptable alternative: store the three aspects as a single `json` column on the snapshot.

**Finding the latest snapshot:** `ORDER BY recorded_at DESC LIMIT 1` on the indexed column. Recommended: denormalize current values onto the audiobook (or cache them) for fast display, treating `rating_snapshots` as history. Pruning old intermediate snapshots is optional housekeeping and must never affect query correctness.

---

### 2.5 User (member)

Standard auth user, extended with notification preferences.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| (standard auth columns) | | name, email, password, etc. |
| `review_notifications_enabled` | boolean default true | Master toggle for review emails. |
| `ratings_notifications_enabled` | boolean default true | Master toggle for rating-change emails. |
| `min_overall` | tinyint default 0 | Minimum overall rating for a review to count as "notable". |
| `min_story` | tinyint default 0 | |
| `min_performance` | tinyint default 0 | |
| `digest_frequency` | enum(`daily`,`weekly`,`off`) default `daily` | A member is on one cadence, not both. `off` suppresses digests. |
| `last_digest_at` | datetime nullable | Cursor: the upper bound of the last digest window. Drives "what's new since" selection (§5). Null until the first digest. |
| timestamps | | |

Admin/owner is a role/flag; the site owner is a normal member who tracks the relevant titles.

---

### 2.6 Tracking (user ↔ audiobook)

The core relationship.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK | |
| `audiobook_id` | FK | |
| `source` | enum(`manual`,`auto`) default `manual` | `auto` = created by autotracking. |
| `created_at` / `updated_at` | timestamps | |

- `UNIQUE (user_id, audiobook_id)` — a member tracks a title at most once.
- Transient per-event state (new review, availability change, etc.) is not stored here; it lives in `notifications` (§2.8).

---

### 2.7 AutotrackRule

A saved search that auto-tracks matching new releases.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK | |
| `search_type` | enum(`author`,`narrator`,`title`) | |
| `term` | string | Search string. |
| timestamps | | |

---

### 2.8 AvailabilityEvent

An append-only log of availability state transitions for an audiobook. This exists because availability is stored as *current state* (§2.1) with no inherent history — and a "became available / unavailable since last digest" question can't be answered from a mutable field (e.g. a title that flapped unavailable→available within a window would look unchanged). Reviews and rating snapshots are already append-only; this gives availability the same property so all digest sources are queryable by time window.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `audiobook_id` | FK | |
| `from_state` | enum(`available`,`unavailable`) | |
| `to_state` | enum(`available`,`unavailable`) | |
| `occurred_at` | datetime | When the availability service resolved the transition. |

- Index `(audiobook_id, occurred_at)`.
- Written by the availability workflow at the moment it flips `audiobooks.availability` (Phase 2 §7). One row per transition; no per-member fan-out.

---

### 2.9 Plan & Subscription (membership)

Default backing tables for the Membership contract (`03-external-integrations.md`).

**`plans`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | e.g. "Standard", "Pro". |
| `max_tracked` | int | Tracking cap. |
| timestamps | | |

**`subscriptions`**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK | |
| `plan_id` | FK | |
| `status` | enum(`active`,`inactive`,`canceled`) | |
| `started_at` | datetime | |
| `ends_at` | datetime nullable | |
| timestamps | | |

> If a billing package owns these tables, the rest of the app still only asks the Membership contract (`isActive`, `maxTracked`) — never the subscription internals.

---

## 3. Invariants

1. **Identity is `(asin, region)`.** Creating an audiobook dedupes on this pair.
2. **A member tracks a title at most once.**
3. **Reviews dedupe on `(source, external_id)`.**
4. **Rating snapshots are append-only and written only on change.** Latest is found by `recorded_at`. Pruning never affects correctness.
5. **`reviews_pending` is set by ratings sync and cleared by review ingestion** — a flag plus an event, decoupled from any schedule.
6. **Availability uses three fields plus an event log:** `unavailable_strikes` counts consecutive failures, `unavailable_since` records the first confirmed failure, `availability` is the resolved state — and every transition is appended to `availability_events` so it remains discoverable after the state field moves on.
7. **Unavailability decay:** once `unavailable_since` is older than 180 days, the title's trackings are removed (bulk delete). This is the only automatic removal of trackings.
8. **Notification preferences are per-member toggles; thresholds are per-aspect minimums.** Toggles gate whether emails go out; minimums gate which reviews are notable and which are visible.
9. **Digests are derived, not queued.** What a member receives is computed at send time from append-only sources within `(last_digest_at, now]`, applying current preferences. There is no per-member pending-notification table.

---

## 4. Visibility is computed, not stored

Whether a member sees a review is a pure function of the review's ratings and the member's thresholds, evaluated at query time:

> A review is **hidden** from a member when `rating_story < user.min_story` **or** `rating_performance < user.min_performance`.

Implement as a query scope, e.g. `Review::visibleTo($user)`. Changing a threshold immediately re-resolves visibility, and review inserts cause no per-member writes.

**Digest eligibility** (whether a review is emailed) is a related but distinct rule, also applied dynamically — it additionally requires `overall ≥ min_overall` and a freshness gate. It is evaluated at digest time, not recorded (§5).

---

## 5. Digests are derived from a cursor, not a queue

A digest answers: *"what has happened to this member's tracked titles since I last emailed them, that is notable under their current preferences?"* It is computed by querying the append-only sources within the window `(last_digest_at, now]` and applying current rules. Nothing is pre-recorded per member.

**Window key — use ingestion time, not source time.** Select rows by *when we recorded them*, so late-arriving data is never missed:
- reviews by `created_at` (a review submitted before the cursor but fetched after must still go out); `submitted_at` is used only for the 30-day freshness gate.
- rating snapshots by `recorded_at`.
- availability events by `occurred_at`.
- auto-trackings by `created_at`.

**Cursor semantics.**
- A single per-member `last_digest_at` (the member is on one cadence — `daily` or `weekly` — so one cursor suffices).
- On a successful send, advance `last_digest_at` to the `now` captured at the start of the run.
- **First digest:** when `last_digest_at` is null, the window starts at a configurable initial lookback (e.g. `now - config('digest.initial_lookback')`), so a new member isn't flooded with backlog.
- **Newly tracked existing title:** its pre-cursor reviews fall outside the window automatically — adding a heavily-reviewed back-catalog title does not dump its history into the next digest. Those reviews remain visible on the title page (§4).

**Consequences (intended).**
- Changing a threshold only affects the *current unsent window* — it never retroactively re-emails already-delivered periods. Full retroactivity for *viewing* is served by `visibleTo` on-site (§4).
- An active-subscription check evaluated at digest time is naturally current — a lapse-then-renew leaves no stale queued items.
- No `notifications` table, no `dispatched_at` bookkeeping, no per-review write fan-out on ingest.

Idempotency is the cursor: a crash before the cursor advances re-sends the same window next run (a rare duplicate at this scale, never a loss). Delivery audit, if ever needed, is left to the mail provider's transactional log rather than a local queue.
