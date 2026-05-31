# Narratic — External Integrations (Phase 3)

**Purpose**: The boundaries to the outside world: the Audible catalog source, the Audiofile Magazine source (deferred post-beta), and the two owned contracts — Membership and Mailer. Each is expressed as a contract or a data shape; transport and efficiency details are implementation choices.

---

## 1. Audible Catalog Source

> **About this source:** the integration uses Audible's `/1.0/catalog/` REST endpoints over plain HTTP GET. It is a long-lived, unauthenticated catalog surface; only the read endpoints below are used. There is no alternative source that exposes review content, so this endpoint *is* the source — the client interface exists for **resilience, caching, and testability** (stub it in tests, cache responses, absorb shape changes), not as a migration path. Rate-limiting, User-Agent handling, retries, and timeouts are concerns of the client implementation, not the domain. Keep the request fingerprint conservative and low-volume.

### 1.1 Client contract

The domain depends only on an interface like:

```
AudibleCatalog {
  search(SearchType type, string term, Region region, page): ProductResult[]
  fetchRatings(string[] asins, Region region): RatingResult[]   // batched internally
  fetchReviews(string asin, Region region, page): ReviewResult[]
}
```

- `SearchType` ∈ `author | narrator | title`.
- `Region` ∈ `US | UK`, mapping to the correct marketplace base. The same ASIN may return different ratings/reviews per region; callers always pass the audiobook's stored region.
- Batching, pagination limits, pacing, and headers live inside the implementation. The domain asks for ratings for a set of ASINs and gets results back.

### 1.2 Data shapes (normalized)

What the client returns to the domain, already normalized away from raw JSON.

**ProductResult** → Audiobook (Phase 1 §2.1 / §2.2)

| Field | → maps to |
|---|---|
| `asin` | `audiobooks.asin` |
| `title`, `subtitle` | `title`, `subtitle` |
| `description` | `description` |
| `runtimeMinutes` | `runtime_minutes` |
| `coverImageUrl` | `cover_image_url` |
| `releaseDate` | `published_at` |
| `authors[]`, `narrators[]` (names) | `contributors` + pivot roles |
| `rating` (optional) | a snapshot (see RatingResult) |

Series/publication name is not stored unless a UI need appears.

**RatingResult** → RatingSnapshot (Phase 1 §2.4)

| Field | → maps to |
|---|---|
| `asin` | match to audiobook |
| `numReviews` | `rating_snapshots.num_reviews` |
| per aspect (`overall`,`story`,`performance`): `average`, `count`, `star1..star5` | `rating_aspect_snapshots` rows |

**ReviewResult** → Review (Phase 1 §2.3)

| Field | → maps to |
|---|---|
| `id` | `external_id` (with `source = audible`) |
| `title` | `title` |
| `submittedAt` | `submitted_at` |
| `authorName` | `author_name` |
| `format` (`freeform`/`guided`) | `format` |
| `body` (freeform) | `body` |
| `guidedResponses[]` (`{question, answer}`) | `guided_responses` |
| `ratings.overall/story/performance` | `rating_overall/story/performance` |
| `authorId` | discarded — unstable across requests, not an identity. |

### 1.3 Error semantics the domain relies on

- **Zeroed rating** = error, not data (Phase 2 §3 guard).
- **Reviews are capped** to recent pages by the client (a couple of pages of most-recent reviews). Full review history is not required.
- Region mismatch yields empty/garbage — never query an ASIN against the wrong region's base.

---

## 2. Audiofile Magazine Source — **Deferred post-beta**

> Audiofile Magazine was acquired by another company. The original scraping approach no longer works and a new integration strategy is needed. This source is out of scope for the beta release.

When eventually implemented, the intent is:

- Produces `ReviewResult`-shaped records with `source = audiofile`, an `external_id`, an optional `related_url`, and `author_name` as initials + copyright year.
- **Matched to an audiobook by normalized title slug** — there is no shared ASIN. This is a deliberately weak join; unmatched reviews are logged, not force-fitted.
- Audiofile reviews **bypass the 30-day notification freshness gate** (Phase 2 §6) — they're treated as authoritative.

The data model already supports `source = audiofile` on the `reviews` table. The integration itself (scraping strategy, auth, source interface) needs to be designed once the new publication situation is clear.

---

## 3. Membership Contract

The domain depends only on:

```
Membership {
  isActive(User): bool          // is the member's subscription currently active?
  maxTracked(User): int         // how many titles may they track?
}
```

**Default implementation** uses the `plans` / `subscriptions` tables (Phase 1 §2.9):
- `isActive` = a subscription with `status = active` and `ends_at` null or in the future.
- `maxTracked` = the active subscription's plan `max_tracked` (a floor of 0 if none).

**Where it's used:**
- **Autotracking** (Phase 2 §5): check `maxTracked` before creating an auto tracking; at cap → skip + log.
- **Notification gating** (Phase 2 §6): `isActive == false` → suppress notifications, leave trackings intact.
- **Manual tracking** (UI): enforce `maxTracked` when a member adds a title.

Adopting a billing package later means writing one adapter to this interface; nothing else changes.

---

## 4. Mailer Contract

Two responsibilities:

**4.1 Sending** — a thin mailer the digest step calls:

```
Mailer {
  send(User, DigestContent): void
}
```

`DigestContent` is assembled at send time by querying the fact tables over the member's window (Phase 2 §8), grouped into:
- **New releases & availability** (auto-trackings + `availability_events`)
- **Rating changes** (net snapshot deltas)
- **New reviews** (reviews passing current thresholds + freshness)

**4.2 Audience** — expressed as queries over our own data. The daily run targets members with `digest_frequency = daily` (the weekly run, `weekly`); a member is sent an email only if their window yields content. No external segment ids or pre-built drafts; the schedule and send time are configuration.

Implementation may be framework Mailables plus the scheduler, an ESP driver (Mailgun/SES/Postmark), or an ecosystem package — as long as it satisfies `Mailer` and reads audience from our tables.

---

## 5. Integration boundary summary

| Concern | Boundary |
|---|---|
| Audible catalog / ratings / reviews | `AudibleCatalog` interface; transport hidden |
| Audiofile reviews | **Deferred post-beta** — data model ready, integration TBD |
| Membership / limits | `Membership` interface over `plans` / `subscriptions` |
| Email / digests | `Mailer` + audience-as-query over `notifications` |

Holding these four boundaries keeps the source's fragility, the billing system, and the mail system out of the domain.
