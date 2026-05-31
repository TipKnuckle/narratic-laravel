# Narratic — System Overview & Phasing

**Status**: Specification for implementation (reference target: Laravel).

---

## 1. What Narratic Is

Narratic lets members **track audiobooks** and receive email notifications when something they care about changes:

- a tracked title's **aggregate ratings** change,
- a **new customer review** that meets the member's quality bar is published,
- a tracked title becomes **unavailable** (or comes back),
- a **new release** matching one of the member's saved searches appears (auto-tracking).

Audiobook data — ratings, reviews, search — comes from an Audible catalog source. Audiofile Magazine reviews were a planned secondary source but are deferred post-beta (the publication was acquired and the integration needs a new approach). Each member has a **membership plan** that caps how many titles they may track and gates whether notifications are sent.

That is the whole domain. The userbase is small (low hundreds at most) and specialized: primarily professional narrators tracking feedback across their own catalogs.

---

## 2. Architecture Conventions

A handful of decisions shape everything downstream. They're listed here so each phase can assume them.

1. **An audiobook is identified by `(asin, region)`.** The same ASIN in two marketplaces is two distinct audiobooks with independent ratings and reviews.

2. **Member preferences are applied dynamically, never frozen.** Both on-site review *visibility* and *digest inclusion* are computed against the member's current thresholds at read/send time — there is no per-member state captured at ingest. (Digest inclusion is the stricter rule of the two; see Phase 1 §4–5.)

3. **Ingestion records append-only facts; it never sends email.** Fetching data produces fact rows (reviews, rating snapshots, availability events, auto-trackings). Digests are *derived* at send time by querying those facts since a per-member cursor — there is no notification queue. This keeps data sync, notification policy, and delivery independently testable.

4. **Scheduling cadence is configuration, not domain.** Specs state what must happen and what may run concurrently. How often each workflow runs lives in the scheduler/queue config.

5. **Membership and mail are accessed through owned contracts.** The domain asks a `Membership` interface "is this member active / what's their cap" and a `Mailer` interface "send this digest." The backing implementation is swappable and never queried directly from domain code.

---

## 3. Phasing

The system is built in the order you would build it from scratch. Each document grounds one phase; later phases depend only on the contracts defined earlier.

| Phase | Document | Deliverable |
|---|---|---|
| 1. Data model | `01-domain-model.md` | Entities, schema, relationships, invariants → migrations + models. |
| 2. Services & workflows | `02-services-and-workflows.md` | Domain services, events, listeners, jobs, and the rules they enforce. |
| 3. External integrations | `03-external-integrations.md` | The Audible catalog client, the Membership + Mailer contracts. (Audiofile source deferred post-beta.) |

Frontend/UI (the member's "My Books" page, audiobook detail, search) is out of scope here; it consumes the Phase 1–2 models and services and can be designed separately.

---

## 4. Glossary

| Term | Meaning |
|---|---|
| **ASIN** | Audible's 10-character product identifier. An external key, not a primary key. |
| **Region** | Marketplace a record comes from (`US`, `UK`). Part of an audiobook's identity. |
| **Aspect** | One of three rating dimensions: `overall`, `story`, `performance`. Each carries an average and a 1–5 star distribution. |
| **Tracking** | A member↔audiobook relationship: "this member wants to hear about this title." |
| **Snapshot** | One point-in-time record of an audiobook's aggregate ratings. |
| **Digest** | A scheduled (daily/weekly) email derived at send time from facts recorded since the member's last digest. |
| **Contract** | An interface the application owns, satisfied by a swappable implementation (used for Membership and Mail). |
