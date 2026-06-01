# ADR 07 — How Digests Decide What to Tell Each User

**Status**: Accepted (2026-05-31)
**Scope**: How the digest decides which changes a member hears about (`02-services-and-workflows.md` §6, §8).
**References**: §2 (ingestion writes facts, never sends mail).

---

## Context

When a tracked book gets a new review, a rating change, or goes in/out of the catalog, the member should hear about it in their next digest — but only if it passes their current preferences (thresholds and on/off toggles).

There are two broad ways to do this:

1. **Decide at send time (pull).** Ingestion just records what happened in the fact tables. When a digest runs, it looks back over the member's window and asks the fact tables "what changed, and does it pass this member's preferences right now?"

2. **Decide at change time (push).** When a book changes, immediately work out who tracks it and record a pending notification for each of them. The digest then just collects whatever is pending.

The spec chose pull. This ADR records why, and — just as importantly — writes down the push-style option we considered so we don't keep re-arguing it later.

---

## Decision

**The digest decides what to send at send time, by querying the fact tables.** Nothing is pre-computed per member when data changes.

### Why pull

- **Preference changes just work.** If a member raises their minimum rating today, their next digest reflects it automatically. There's nothing stored from before that we'd have to go back and fix.
- **No extra moving parts.** No notifications table, no queue, no per-member fan-out on every change. Ingestion stays simple: it records facts and is unaware that users even exist.
- **It fits our scale.** With well under 100 members, the digest can afford to look things up fresh each run.

### The cost, and how we keep it small

The trade-off is that the digest run does more work, because it re-derives everything instead of reading a ready-made list. The way to keep that cheap is the **query shape**:

> For each member, run **one set-based query per section** (reviews, rating changes, availability, new releases) — each limited to the books that member tracks and the member's time window.

That's four queries per member, flat — *not* a query per tracked book. The earlier version looped over every tracked book and ran two queries each, which for a member tracking thousands of books meant thousands of queries. The set-based shape makes that a non-issue. There is nothing to cache: each query's result *is* the content we render.

---

## The push-style option we did **not** take (and when to revisit)

The alternative — sometimes called a "dirty marker" approach — is to flag work when a change happens instead of discovering it at send time. Two flavors:

- **Per book + user:** when a book changes, add the tracking members' ids to a "needs attention" set on the book. The digest pulls books with anything outstanding and clears the markers as it sends.
- **Per user:** when a tracked book changes, flip a single "has updates" flag on each tracking member. The digest only looks at flagged members.

This is a reasonable pattern and a real option at larger scale. We're not using it now for three reasons:

1. **It couples the write side to users.** Today, recording a rating change knows nothing about who tracks the book. Adding "and mark the trackers" drags user-awareness into every ingestion path — the exact thing the fact-table design keeps clean.
2. **A missed flag becomes a missed digest, silently.** If a marker is wrongly cleared, that member just gets skipped — and nothing re-checks them until the next unrelated change flags them again. The pull approach has no such hole: it always looks at everyone inside their window, so a glitch self-corrects next run.
3. **At our scale it saves almost nothing.** Once the per-section query shape is in place, checking a member with no news is a handful of fast indexed lookups. A flag exists to skip that — and that's not worth a correctness risk.

**When to revisit:** if membership grows large enough (think tens of thousands), or the "any news?" check stops being cheap, a marker becomes worthwhile. The clean way to add it then:

- Ingestion keeps recording facts and **emits the domain events it already emits** (`RatingsChanged`, `ReviewPublished`, `BecameAvailable`, etc.).
- **A listener** — not the ingestion services — reacts to those events and maintains the marker set. The coupling lives in one place and can be removed.
- The marker only decides **who to look at**, never **what goes in the email**. The member's time window stays the source of truth, so the digest content is still derived fresh and still respects current preferences.

---

## A note on availability

Availability changes (a tracked book leaving or returning to the catalog) are just one of the digest's sections, read from the `availability_events` table like everything else. They have two small rule differences: they ignore the review/rating on-off toggles, and a book that left and came back within one window is reported as its net effect, not as two events.

If "a book you track is leaving soon" ever needs to be **timely** — sent right away rather than waiting for the next digest — the `BecameUnavailable` event is already the hook for a separate, more urgent alert, with no change to the digest. That's a product decision for later, tracked as a feature issue.

---

## Cross-reference

This ADR backs `02-services-and-workflows.md` §6 and §8. No code or schema change is required by the decision itself; the related correctness and query-shape fixes are tracked as separate issues.
