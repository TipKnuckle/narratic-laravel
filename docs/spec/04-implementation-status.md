# Narratic — Implementation Status

> **Purpose**: Track what's built, what's in progress, and what's still needed against the three-phase spec. Update this document as work progresses.

---

## Legend

| Icon | Meaning |
|---|---|
| ✅ Done | Implemented, tested, working |
| 🔶 Partial | Started but incomplete |
| ❌ Missing | Not yet implemented |
| 🗑️ Removed/Deferred | Deliberately cut or postponed |
| 🏗️ In Progress | Being actively worked on |

---

## Phase 1 — Domain Model (Data Model)

| # | Entity / Concern | Status | Notes |
|---|---|---|---|
| 1.1 | **Audiobook** table + model | ✅ Done | Migrations, Model, Factory all exist. Constraints: `UNIQUE(asin,region)`, indexes on `ratings_synced_at`, `availability_checked_at`, `reviews_pending`. |
| 1.2 | **Contributor** table + model | ✅ Done | Migration, Model, Factory. Slug-based matching. |
| 1.3 | **audiobook_contributor** pivot | ✅ Done | Migration, Pivot model. `UNIQUE(audiobook_id, contributor_id, role)`. |
| 1.4 | **Review** table + model | ✅ Done | Migration, Model. `UNIQUE(source, external_id)`, index `(audiobook_id, submitted_at)`. Supports `source=audiofile` (reserved). |
| 1.5 | **RatingSnapshot** table + model | ✅ Done | Migration, Model. Index `(audiobook_id, recorded_at)`. |
| 1.6 | **RatingAspectSnapshot** table + model | ✅ Done | Migration, Model. `UNIQUE(rating_snapshot_id, aspect)`. |
| 1.7 | **User** notification columns | ✅ Done | Migration adds `review_notifications_enabled`, `ratings_notifications_enabled`, `min_overall`, `min_story`, `min_performance`, `digest_frequency`, `last_digest_at`. Model casts set. |
| 1.8 | **Tracking** table + model | ✅ Done | Migration, Model. `UNIQUE(user_id, audiobook_id)`. `source` enum (`manual`, `auto`). |
| 1.9 | **AutotrackRule** table + model | ✅ Done | Migration, Model. |
| 1.10 | **AvailabilityEvent** table + model | ✅ Done | Migration, Model. `$timestamps = false`. Index `(audiobook_id, occurred_at)`. |
| 1.11 | **Plan** table + model | ❌ Missing | Needed for Membership default impl. |
| 1.12 | **Subscription** table + model | ❌ Missing | Needed for Membership default impl. |
| 1.13 | `Review::visibleTo($user)` scope | ❌ Missing | Computed visibility: `rating_story >= min_story AND rating_performance >= min_performance`. |
| 1.14 | **Factories** | ✅ Done | Audiobook, Review, Contributor, RatingSnapshot, RatingAspectSnapshot, Tracking, AutotrackRule, AvailabilityEvent, User factories all exist. |

**Phase 1 status: ~85% done.** Plans & Subscriptions tables/models and the `visibleTo` scope are the remaining gaps.

---

## Phase 2 — Services & Workflows

### 2.1 Catalog Search & Audiobook Ingestion

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.1a | `CatalogSearchService` — search + upsert | ✅ Done | Calls `AudibleCatalog::search()`, upserts audiobooks, syncs contributors. Tests exist. |
| 2.1b | `ContributorService` — findOrCreate by slug | ✅ Done | Unit + feature tests. |

### 2.2 Ratings Sync

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.2a | Select "due" titles for refresh | ✅ Done | `Audiobook::dueForRatingsSync()` selects on `next_check_at` (nulls first, pre-orders excluded, config batch limit). Adaptive cadence per `05-adr-ratings-sync-cadence.md`; `RatingsSyncService` stamps `next_check_at` on every outcome. |
| 2.2b | Fetch current ratings from Audible | ✅ Done | `AudibleCatalog::fetchRatings()` exists and works. No caller yet. |
| 2.2c | Compare vs latest stored snapshot | ✅ Done | Diff logic: compare `average`, `count`, `star_1..star_5` per aspect. |
| 2.2d | Write new snapshot only on change | ✅ Done | Append-only invariant. |
| 2.2e | Zeroed-rating guard | ✅ Done | `RatingResult::isZeroed` exists. No guard logic in a sync service yet. |
| 2.2f | Set `reviews_pending=true` when `num_reviews` rises | ✅ Done | Trigger for review ingestion. |
| 2.2g | Stamp `ratings_synced_at` | ✅ Done | After successful sync. |
| 2.2h | Emit `RatingsChanged` event | ❌ Missing | Domain event for future consumers. |

### 2.3 Review Ingestion

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.3a | Select titles with `reviews_pending=true` | ❌ Missing | Query + loop. |
| 2.3b | Fetch recent reviews from Audible | 🔶 Partial | `AudibleCatalog::fetchReviews()` exists. No caller yet. |
| 2.3c | Dedupe on `(source, external_id)` | ❌ Missing | Insert only new reviews. |
| 2.3d | Parse freeform vs guided formats | ❌ Missing | Store `body` or `guided_responses` accordingly. |
| 2.3e | Clear `reviews_pending` after fetch | ❌ Missing | Regardless of success/failure. |
| 2.3f | Emit `ReviewPublished` event | ❌ Missing | Domain event. |

### 2.4 Autotracking

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.4a | Iterate all `AutotrackRule` rows | ❌ Missing | Loop over rules per member. |
| 2.4b | Search Audible by rule's `search_type`/`term` | 🔶 Partial | `CatalogSearchService` can do this, but no autotrack workflow calls it. |
| 2.4c | Filter to genuinely new releases (7-day window) | ❌ Missing | `published_at` within configurable window. |
| 2.4d | Skip already-tracked titles (dedupe) | ❌ Missing | Check existing `trackings`. |
| 2.4e | Respect `Membership.maxTracked` cap | ❌ Missing | Skip + log if at cap. |
| 2.4f | Create `Tracking` with `source=auto` | ❌ Missing | After all checks pass. |
| 2.4g | Emit `NewReleaseAutoTracked` event | ❌ Missing | Domain event. |

### 2.5 Digest Selection Rules

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.5a | Common gates (active tracking, active subscription) | ❌ Missing | Query logic for digest assembly. |
| 2.5b | New reviews — age gate (30-day `submitted_at` freshness) | ❌ Missing | Plus threshold checks. |
| 2.5c | Rating changes — net delta over window | ❌ Missing | Compare snapshots at window boundaries. |
| 2.5d | Availability changes — events in window | ❌ Missing | Collapse flaps to net effect. |
| 2.5e | New releases — auto-trackings in window | ❌ Missing | `source=auto` + `created_at` in window. |

### 2.6 Availability Checking

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.6a | Select titles due for a check | ❌ Missing | Not checked in ~7 days. **Pattern decided**: use materialized `next_availability_check_at` + claimer tick, mirroring Ratings Sync (ADR 06). |
| 2.6b | Probe availability via Audible | ❌ Missing | Hit catalog endpoint, check response. |
| 2.6c | Strike threshold (2 failures → unavailable) | ❌ Missing | Increment `unavailable_strikes`. |
| 2.6d | Write `availability_events` on transition | ❌ Missing | Append-only log. |
| 2.6e | Reset on recovery (available after unavailable) | ❌ Missing | Clear strikes, clear `unavailable_since`. |
| 2.6f | 180-day decay → remove trackings | ❌ Missing | Bulk delete trackings for stale-unavailable titles. |

### 2.7 Digests (Delivery)

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.7a | Resolve window `(last_digest_at, now]` | ❌ Missing | Handle null (initial lookback). |
| 2.7b | Assemble digest sections from fact tables | ❌ Missing | Query reviews/snapshots/events/trackings per member. |
| 2.7c | Apply current preferences (toggles, thresholds) | ❌ Missing | All checks at send time. |
| 2.7d | Render digest content (`DigestContent`) | ❌ Missing | Data structure for mailer. |
| 2.7e | Send via `Mailer` contract | ❌ Missing | Only when content is non-empty. |
| 2.7f | Advance `last_digest_at` on success | ❌ Missing | Cursor-based idempotency. |
| 2.7g | Skip empty digests | ❌ Missing | No empty emails. |
| 2.7h | Handle `digest_frequency = off` | ❌ Missing | Skip those members entirely. |
| 2.7i | Daily run targets `daily` members; weekly run targets `weekly` | ❌ Missing | Audience selection. |

### 2.8 Scheduling

| # | Concern | Status | Notes |
|---|---|---|---|
| 2.8a | Ratings sync scheduled job | ✅ Done | `audiobook:sync-ratings` scheduled every 5 min (`withoutOverlapping`) in `routes/console.php` — a claimer tick; `next_check_at` governs actual volume. |
| 2.8b | Review ingestion scheduled job | ❌ Missing | Console command + schedule. |
| 2.8c | Availability check scheduled job | ❌ Missing | Console command + schedule. |
| 2.8d | Autotracking scheduled job | ❌ Missing | Console command + schedule. |
| 2.8e | Digests scheduled job (daily + weekly) | ❌ Missing | Console command + schedule. |

**Phase 2 status: ~10% done.** The search/ingestion pipeline works. All other workflows are unimplemented.

---

## Phase 3 — External Integrations

| # | Concern | Status | Notes |
|---|---|---|---|
| 3.1 | `AudibleCatalog` contract | ✅ Done | Interface with `search()`, `fetchRatings()`, `fetchReviews()`. |
| 3.2 | `AudibleApiClient` (implementation) | ✅ Done | HTTP client with batching, retries, timeouts, regional routing. Tests exist. |
| 3.3 | DTOs (`ProductResult`, `RatingResult`, `ReviewResult`) | ✅ Done | Normalized data shapes with `fromApiResponse()` factories. Tests exist. |
| 3.4 | `Region` enum (`US`, `UK`) | ✅ Done | Maps to `api.audible.com` / `api.audible.co.uk`. |
| 3.5 | `SearchType` enum | ✅ Done | `author`, `narrator`, `title`, `publisher`. |
| 3.6 | Audiofile Magazine source | 🗑️ Deferred post-beta | Schema ready (`source=audiofile`). Integration TBD. |
| 3.7 | `Membership` contract | ✅ Done | Interface with `isActive(User)` + `maxTracked(User)`. |
| 3.8 | Membership default impl (dev stub) | ✅ Done | `DevMembership` — always active, unlimited. Swap when billing library chosen. Plans/subscriptions tables deferred. |
| 3.9 | `Mailer` contract | ❌ Missing | `send(User, DigestContent)` interface. |
| 3.10 | `DigestContent` DTO | ❌ Missing | Data structure for digest sections. |
| 3.11 | Mailer implementation | ❌ Missing | Framework Mailables or ESP driver. |
| 3.12 | `AppServiceProvider` bindings | 🔶 Partial | `AudibleCatalog` and `Membership` bound. `Mailer` not yet bound. |

**Phase 3 status: ~40% done.** Audible integration is complete. Membership and Mailer contracts plus implementations are missing.

---

## Testing

| # | Concern | Status | Notes |
|---|---|---|---|
| T1 | `CatalogSearchServiceTest` | ✅ Done | Ingestion, contributor sync, upsert. |
| T2 | `AudibleApiClientTest` | ✅ Done | Search, ratings, reviews, regional routing. |
| T3 | `ContributorServiceTest` (Feature) | ✅ Done | Create + return existing. |
| T4 | `ContributorServiceTest` (Unit) | ✅ Done | Slugify logic. |
| T5 | `ProductResultTest` (Unit) | ✅ Done |
| T6 | `RatingsSyncServiceTest` (Feature) | ✅ Done |
| T7 | `SyncRatingsCommandTest` (Feature) | ✅ Done | Parsing, rating extraction, zeroed detection. |

---

## Console Commands

| # | Command | Status | Notes |
|---|---|---|---|
| C1 | `audible:details` | ✅ Done |
| C2 | `audiobook:sync-ratings` | ✅ Done | Fetches ratings + reviews for a given ASIN. Debugging tool. |

---

## Summary

| Phase | Done | Partial | Missing | Deferred |
|---|---|---|---|---|
| Phase 1 — Data Model | ~85% | 0% | ~15% | 0% |
| Phase 2 — Services & Workflows | ~25% | ~0% | ~75% | 0% |
| Phase 3 — External Integrations | ~40% | ~5% | ~55% | ~5% (Audiofile) |
| **Overall** | **~50%** | **~0%** | **~45%** | **~5%** |

---

## Next priority work (suggested order)

1. ~~Membership contract + default impl~~ ✅ Done
2. ~~Ratings sync service~~ ✅ Done
3. **Review ingestion service** (Phase 2 §4) — consumes `reviews_pending` flag, fetches and stores new reviews.
4. **Availability check service** (Phase 2 §7) — detects unavailable titles, manages strike logic, event logging, 180-day decay.
5. **Autotracking workflow** (Phase 2 §5) — runs saved searches, respects caps, creates auto trackings.
6. **Digest assembly + delivery** (Phase 2 §6 + §8) — query fact tables, apply preferences, send via Mailer contract.
7. **Mailer contract + implementation** (Phase 3 §4) — needed by digest delivery.
8. **Review `visibleTo` scope** (Phase 1 §4) — computed visibility for on-site display.
9. **Scheduled jobs** (Phase 2 §9) — wire everything into the scheduler at configured cadences.
