# Audible API Specification (v1.0 Catalog)

**Source**: Reverse-engineered from the Narratic WordPress plugin (`narratic.php`, `narratic-prospector.php`).  
**Purpose**: Reference document for implementing equivalent Audible data integrations in other frameworks or platforms.  
**Version Implication**: All endpoints use path segment `/1.0/`, indicating this is Audible's v1 REST catalog API.

---

## 1. Overview

Audible provides a REST API for querying audiobook product data including search, ratings, and customer reviews. The API is region-scoped — each marketplace (US, UK) has its own base domain. All responses are JSON. Authentication does not appear to require an API key in the observed usage patterns; requests use standard HTTP GET with randomised User-Agent headers to avoid rate-limiting.

### Base Domains by Region

| Region Code | Base Domain |
|---|---|
| `US` | `api.audible.com` |
| `UK` | `api.audible.co.uk` |

**Base URL pattern**:  
```
https://{region}/1.0/{endpoint}
```

All observed endpoints live under `/catalog/`.

---

## 2. Endpoint: Search Products

Find audiobooks by narrator, author, title keyword, or publisher.

### Request

```
GET https://{region}/1.0/catalog/products
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `narrator` | string | See note | — | Search for audiobooks narrated by this person. **Mutually exclusive** with `author`, `title`, and `publisher`. |
| `author` | string | See note | — | Search for books by this author. |
| `title` | string | See note | — | Free-text search on book titles. |
| `publisher` | string | See note | — | Search by publisher name. |
| `products_sort_by` | string | Yes | — | Determines sort order and which search field to use (see Sort Options table). |
| `page` | integer | No | `0` | Zero-indexed page number. Each page returns `num_results` items. |
| `num_results` | integer | No | `50` | Number of results per page. Maximum observed in use: 900+ (prospector uses this for bulk fetch). |
| `response_groups` | string | Yes | — | Comma-separated list of data groups to include (see Response Groups table). |
| `image_sizes` | string | No | — | Comma-separated list of image size keys to include in `product_images`. Observed: `"100,500"`. |

> **Note on search fields**: The code maps the four search types (`narrator`, `author`, `title`, `publisher`) through `products_sort_by`, which both determines sort order and implicitly selects which query parameter carries the search term. In practice:
> - `narrator` → set `products_sort_by=-ReleaseDate`, use `narrator=<term>`
> - `author` → set `products_sort_by=-ReleaseDate`, use `author=<term>`
> - `title` → set `products_sort_by=Relevance`, use `title=<term>`
> - `publisher` → set `products_sort_by=-ReleaseDate`, use `publisher=<term>`
>
> The `narrator` and `author` searches are sorted by newest release date descending (`-ReleaseDate`). The `title` search uses relevance scoring. There is no explicit `keyword=` parameter — the search type IS the query parameter name.

### Sort Options

| products_sort_by value | Meaning | Applicable search types |
|---|---|---|
| `-ReleaseDate` | Newest first (descending) | `narrator`, `author`, `publisher` |
| `Relevance` | Ranked by match quality | `title` |

### Response Groups

Specifies which data sections to include in each product object. Combine multiple with commas:

| Group | Description | Key fields included |
|---|---|---|
| `product_desc` | Title, description, and metadata | `title`, `subtitle`, `publisher_summary`, `publication_name`, `release_date` |
| `contributors` | Author and narrator information | `authors[]`, `narrators[]` |
| `rating` | Aggregate ratings data | `rating` object with distributions per aspect |
| `product_attrs` | Product attributes | `runtime_length_min` (minutes) |
| `product_extended_attrs` | Extended product details | Additional metadata fields |
| `media` | Image assets | `product_images[100]`, `product_images[500]` |

**Minimum viable set for full audiobook ingestion**:  
```
response_groups=product_desc,contributors,rating,product_attrs,product_extended_attrs,media
```

### Example Request

```
GET https://api.audible.com/1.0/catalog/products?response_groups=product_desc,contributors,rating,product_attrs,product_extended_attrs,media&products_sort_by=-ReleaseDate&narrator=Julia%20Whelan&page=0&num_results=50&image_sizes=100,500
```

### Example Response

```json
{
  "products": [
    {
      "asin": "B07DXYZ123",
      "title": "The Fifth Season",
      "subtitle": null,
      "authors": [
        {
          "name": "N.K. Jemisin"
        }
      ],
      "narrators": [
        {
          "name": "Gabriel Le Dit"
        }
      ],
      "publisher_summary": "A stunning debut novel...",
      "publication_name": "The Broken Earth, Book 1",
      "release_date": "2018-05-08T00:00:00.000Z",
      "runtime_length_min": 630,
      "rating": {
        "num_reviews": 12847,
        "overall_distribution": {
          "average_rating": 4.5,
          "num_ratings": 8234,
          "num_one_star_ratings": 120,
          "num_two_star_ratings": 245,
          "num_three_star_ratings": 890,
          "num_four_star_ratings": 2340,
          "num_five_star_ratings": 4639
        },
        "story_distribution": {
          "average_rating": 4.4,
          "num_ratings": 7100,
          "num_one_star_ratings": 150,
          "num_two_star_ratings": 280,
          "num_three_star_ratings": 950,
          "num_four_star_ratings": 2100,
          "num_five_star_ratings": 4620
        },
        "performance_distribution": {
          "average_rating": 4.7,
          "num_ratings": 7800,
          "num_one_star_ratings": 80,
          "num_two_star_ratings": 150,
          "num_three_star_ratings": 400,
          "num_four_star_ratings": 1900,
          "num_five_star_ratings": 5270
        }
      },
      "product_images": {
        "100": "https://m.media-amazon.com/images/I/..._SL100_.jpg",
        "500": "https://m.media-amazon.com/images/I/..._SL500_.jpg"
      }
    }
  ],
  "total_results": 342,
  "page": 0,
  "num_results": 50
}
```

### Response Schema — `products[]` Array

| Field | Type | Description |
|---|---|---|
| `asin` | string (10 chars) | Amazon Standard Identification Number — unique product key |
| `title` | string | Display title of the audiobook |
| `subtitle` | string \| null | Subtitle, if present |
| `authors[]` | array of `{name: string}` | List of contributing authors |
| `narrators[]` | array of `{name: string}` | List of narrators |
| `publisher_summary` | string | Publisher-provided description / blurb |
| `publication_name` | string \| null | Series name, if the book belongs to a series |
| `release_date` | ISO 8601 datetime | Publication/release date |
| `runtime_length_min` | integer | Total runtime in minutes |
| `rating` | object | Aggregate rating data (see below) |
| `product_images[100]` | string | Thumbnail cover URL (100px width) |
| `product_images[500]` | string | Standard cover URL (500px width) |

### Response Schema — `rating` Object

The rating object is structured around three **aspects**, each containing a distribution:

| Field | Type | Description |
|---|---|---|
| `num_reviews` | integer | Total number of written reviews for this title |
| `overall_distribution.average_rating` | float (0-5, 1 decimal) | Weighted average overall rating |
| `overall_distribution.num_ratings` | integer | Total count of ratings received |
| `overall_distribution.num_one_star_ratings` through `num_five_star_ratings` | integer | Star breakdown |
| `story_distribution.*` | same structure | Story/narrative aspect ratings |
| `performance_distribution.*` | same structure | Narration performance aspect ratings |

> **Key insight — Three-aspect model**: Every audiobook has three independent rating dimensions: `overall`, `story`, and `performance`. Each dimension maintains its own star distribution (1-5) and average. This is a core structural pattern throughout the API.

### Pagination

| Field | Type | Description |
|---|---|---|
| `total_results` | integer | Total matching products across all pages |
| `page` | integer | The current page number (zero-indexed) |
| `num_results` | integer | Results returned per page |

To paginate: increment `page` and repeat. Stop when fewer than `num_results` are returned or `total_results` is exhausted.

---

## 3. Endpoint: Batch Ratings Lookup

Fetch ratings for multiple products in a single request. This is the primary method for **upstream rating checks** — comparing stored ratings against current API data to detect changes.

### Request

```
GET https://{region}/1.0/catalog/products
```

### Query Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `asins` | string (CSV) | Yes | Comma-separated list of ASINs to look up. **Maximum batch size**: 50 per request (observed from `array_chunk($regPids, 50)`). |
| `response_groups` | string | Yes | Must include `rating`. Value: `"rating"` |

### Example Request

```
GET https://api.audible.com/1.0/catalog/products?response_groups=rating&asins=B07DXYZ123,B08ABC456,B09DEF789
```

### Example Response

```json
{
  "products": [
    {
      "asin": "B07DXYZ123",
      "rating": {
        "num_reviews": 12900,
        "overall_distribution": {
          "average_rating": 4.51,
          "num_ratings": 8250,
          "num_one_star_ratings": 118,
          "num_two_star_ratings": 243,
          "num_three_star_ratings": 887,
          "num_four_star_ratings": 2338,
          "num_five_star_ratings": 4664
        },
        "story_distribution": {
          "average_rating": 4.41,
          "num_ratings": 7110,
          "num_one_star_ratings": 148,
          "num_two_star_ratings": 278,
          "num_three_star_ratings": 948,
          "num_four_star_ratings": 2095,
          "num_five_star_ratings": 4641
        },
        "performance_distribution": {
          "average_rating": 4.71,
          "num_ratings": 7820,
          "num_one_star_ratings": 78,
          "num_two_star_ratings": 148,
          "num_three_star_ratings": 398,
          "num_four_star_ratings": 1895,
          "num_five_star_ratings": 5292
        }
      }
    },
    {
      "asin": "B08ABC456",
      "rating": {
        ...
      }
    }
  ],
  "total_results": 3,
  "page": 0,
  "num_results": 50
}
```

### Invariants & Error Handling

1. **Zeroed-out ratings are an error state.** If `overall_distribution.num_ratings` is `0` when it previously had a positive value, the API may be returning a stale/errored response. The consuming system tracks a `narratic_zeroed` counter and skips updates after >2 consecutive zeroed responses.

2. **Review count can decrease.** If `num_reviews` drops between checks (e.g., a review was removed), this is logged as a warning but the system still records it — there's no assumption that counts are monotonically increasing.

3. **Rate limiting concern**: The code uses randomized User-Agent strings (`narratic_uagent()`) and sleeps between bulk prospect searches (1 second). This implies the API may rate-limit or block repeated requests from identical fingerprints.

---

## 4. Endpoint: Product Reviews

Fetch customer-written reviews for a specific audiobook, paginated.

### Request

```
GET https://{region}/1.0/catalog/products/{asin}/reviews/
```

### Query Parameters

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `asin` (path) | string | Yes | — | The 10-character ASIN of the audiobook |
| `sort_by` | string | No | `"MostRecent"` | Sort order. Only observed value: `"MostRecent"` |
| `num_results` | integer | No | `15` | Results per page. Hardcoded to 15 in usage. |
| `page` | integer | No | `0` | Zero-indexed page number |

### Example Request

```
GET https://api.audible.com/1.0/catalog/products/B07DXYZ123/reviews/?sort_by=MostRecent&num_results=15&page=0
```

### Example Response

```json
{
  "customer_reviews": [
    {
      "id": "MR3ABC123",
      "title": "Absolutely brilliant narration!",
      "submission_date": "2024-03-15T14:32:00.000Z",
      "author_name": "Jane M.",
      "format": "Freeform",
      "body": "This audiobook kept me on the edge of my seat. The narrator brings each character to life with distinct voices and emotional depth that enhances the story significantly.",
      "ratings": {
        "overall_rating": 5,
        "story_rating": 5,
        "performance_rating": 5
      },
      "guided_responses": [
        {
          "question": "How does the narration compare to the book?",
          "answer": "Better than I expected"
        },
        {
          "question": "Would you listen to this again?",
          "answer": "Yes"
        }
      ]
    },
    {
      "id": "MR3DEF456",
      "title": "Good but slow start",
      "submission_date": "2024-03-10T09:15:00.000Z",
      "author_name": "Mike R.",
      "format": "Guided",
      "ratings": {
        "overall_rating": 4,
        "story_rating": 3,
        "performance_rating": 5
      },
      "guided_responses": [
        {
          "question": "Was this a gift?",
          "answer": "No"
        }
      ]
    }
  ],
  "total_results": 47,
  "page": 0,
  "num_results": 15
}
```

### Review Object Schema

| Field | Type | Description |
|---|---|---|
| `id` | string | Unique review identifier (prefix like `MR3`). Used as the deduplication key. |
| `title` | string | Review headline/summary |
| `submission_date` | ISO 8601 datetime | When the review was submitted |
| `author_name` | string | Reviewer display name |
| `author_id` | string \| null | Internal reviewer ID — **not stable**, differs per request, not usable for identity resolution |
| `format` | enum `"Freeform"` or `"Guided"` | Review type (see below) |
| `body` | string | Full review text. Present only when `format === "Freeform"`. |
| `ratings.overall_rating` | integer 1-5 | Overall quality score |
| `ratings.story_rating` | integer 1-5 | Story/narrative score |
| `ratings.performance_rating` | integer 1-5 | Narration performance score |
| `guided_responses[]` | array | Present only when `format === "Guided"`. Each item has `question` and `answer` strings. |

### Review Format Handling

The API returns reviews in two distinct formats, each requiring different parsing:

**Freeform format** (`format: "Freeform"`):
- Has a `body` field with the complete review text
- May or may not have `guided_responses`
- The body is the primary content

**Guided format** (`format: "Guided"`):
- No `body` field — structured Q&A instead
- `guided_responses[]` contains question-answer pairs
- Consumer should render these as a definition list (`<dt>/<dd>`) or equivalent structured display

### Pagination

The reviews endpoint returns exactly 15 results per page when that many (or more) exist. To fetch all pages:

```
Continue incrementing `page` until the response contains fewer than 15 items,
OR until you hit a page limit (consumer code caps at configurable pagemax, default 2).
```

> **Important**: The consumer implementation limits pages to `pagemax` (default 2, max observed: 10) — meaning only the 30 most recent reviews are fetched per audiobook. Older reviews are not retrieved. This is a design constraint of the consuming application, not an API limit.

---

## 5. Region Considerations

The Audible API is partitioned by marketplace. The same ASIN may have different ratings or availability depending on region.

### Domain Mapping

```
US:  api.audible.com
UK:  api.audible.co.uk
```

### How Regions Affect Each Endpoint

| Endpoint | Region Behavior |
|---|---|
| Search (`/products`) | Results and ratings are region-specific. A narrator search on the UK API returns UK-published titles. |
| Batch Ratings (`?asins=`) | Region-scoped. The same ASIN batch queried against US vs UK will return different rating numbers. |
| Reviews (`/{asin}/reviews/`) | Reviews are specific to the region where the audiobook was purchased/listed. |

### Implication for Data Model

Each audiobook record in a consuming system must store its `region` as an attribute. When fetching ratings or reviews, the consumer must route requests to the correct regional endpoint based on the audiobook's stored region. Mixing regions (querying a UK ASIN against the US API) will return no data or incorrect data.

---

## 6. Rate Limiting & Best Practices (Inferred from Usage)

While explicit rate-limit documentation is not available in this codebase, the consuming system implements several defensive strategies that reveal practical constraints:

### Observed Defensive Patterns

| Pattern | Code Location | Purpose |
|---|---|---|
| **Randomised User-Agent** | `narratic_uagent()` — rotates through 12 browser UA strings per request | Avoid fingerprint-based blocking |
| **Request chunking** | `array_chunk($regPids, 50)` in ratings lookup | Keep batch sizes at 50 ASINs max |
| **Region isolation** | Separate API calls per region before batching | Prevent cross-region data mixing |
| **Sleep between bulk requests** | `sleep(1)` in prospector loop | Throttle outbound request rate |
| **Caching via last_update** | `meta_query: last_update <= $date` | Only query products that haven't been checked recently |
| **Adaptive throttling** | Rating age → check frequency (120d→5d, 60d→4d, 30d→3d) | Reduce API calls for stale/unchanged data |
| **Timeout handling** | `timeout: 30` for search, `timeout: 90` for bulk/fetch | Handle slow responses gracefully |

### Suggested Rate Limits (from observed patterns)

| Operation | Recommended Frequency | Batch Size |
|---|---|---|
| Search by narrator/author | As needed (no throttling in code) | 50-100 results per page |
| Ratings lookup | Every 3–120 days depending on data freshness | Max 50 ASINs per request |
| Reviews fetch | Only when `num_reviews` has changed | 15 reviews per page, cap at configurable page limit |
| Prospector bulk search | With 1-second pause between ASIN queries | Depends on total results (can be hundreds) |

---

## 7. Data Transformation Map (API → Consuming System)

This section maps raw API fields to how they are stored and used in the Narratic system — useful for understanding what data is essential vs. decorative.

### Search Results → Audiobook Entity

| API Field | Storage Location | Notes |
|---|---|---|
| `asin` | Post meta: `ASIN` | **Primary key** — unique per audiobook record |
| `title` | `post_title` | Display title of the book |
| `publisher_summary` | `post_content` | Book description |
| `release_date` | `post_date` + meta: `last_update` | Used for both display and throttling decisions |
| `runtime_length_min` | Post meta: `length` | Integer minutes |
| `authors[0].name` (all) | Taxonomy `author` terms | Each author becomes a taxonomy term on the audiobook |
| `narrators[0].name` (all) | Taxonomy `narrator` terms | Each narrator becomes a taxonomy term on the audiobook |
| `product_images[500]` | Post meta: `image_URL` + featured image attachment | Image is downloaded and stored as a WordPress attachment |
| `product_images[100]` | (discarded) | Thumbnail only used in UI, not persisted |
| `publication_name` | Not stored | Series name is fetched but never saved to the database |

### Ratings API → Ratings Table

| API Field | Storage Location | Notes |
|---|---|---|
| Each product's `rating.*` | New row in `{prefix}narratic_ratings` table | Every change creates a new time-series record |
| `num_reviews` | Rating table: `num_reviews` | Monitored for increase → triggers review fetch cycle |
| Distribution counts (1-5) per aspect | Individual columns (`overall_1` through `overall_5`, etc.) | 9 distribution count columns total |
| Average ratings per aspect | Columns `overall_avg`, `story_avg`, `performance_avg` | May be recomputed rather than stored directly |

### Reviews API → Review Entity

| API Field | Storage Location | Notes |
|---|---|---|
| `id` | Post meta: `review_id` | **Deduplication key** — no two reviews share an ID |
| `title` | `post_title` | Review headline |
| `submission_date` | `post_date_gmt` | Preserved exactly from the API |
| `author_name` | Post meta: `author_name` | Display name of reviewer |
| `ratings.overall_rating` | Post meta: `rating_overall` | Integer 1-5 |
| `ratings.story_rating` | Post meta: `rating_story` | Integer 1-5 |
| `ratings.performance_rating` | Post meta: `rating_performance` | Integer 1-5 |
| `body` (Freeform) or structured Q&A | `post_content` | Freeform → raw text. Guided → HTML `<dl>` with questions/answers |
| Product ASIN lookup | `post_parent` → parent audiobook post ID | Reviews are child posts; the audiobook is found via parent relationship |

---

## 8. API Changelog (Inferred)

Based on code comments and deprecated patterns found in the codebase:

| Version / Date | Change | Evidence |
|---|---|---|
| Original (uncommented URL) | Reviews endpoint was `/1.0/catalog/products/{asin}/?response_groups=reviews,review_attrs&reviews_sort_by=MostRecent&reviews_num_results=10&page={n}` | Commented-out URL in `narratic_reviews_api_fetch()` |
| Current | Reviews endpoint moved to dedicated path: `/1.0/catalog/products/{asin}/reviews/` with query params `sort_by`, `num_results`, `page` | Active code uses the new pattern; old URL is a commented alternative |
| Current | Review `author_id` field observed but explicitly discarded — "different for every review" | Comment in `narratic_reviews_api_fetch()` |

---

## 9. Complete Request/Response Flow (Typical Usage)

This is the standard data refresh cycle that a consuming system would implement:

```
1. QUERY: Get all tracked audiobooks needing an update
   └─ Local DB query: WHERE last_update <= now() - N_days
   
2. FETCH RATINGS for each audiobook (batched by region, 50 per request)
   GET https://{region}/1.0/catalog/products?response_groups=rating&asins=A,B,C...
   
3. COMPARE: For each audiobook, compare last stored rating row with current API response
   
4A. IF ratings changed → write new row to local ratings table (time-series)
    └─ Notify all users tracking this audiobook that ratings have changed
   
4B. IF num_reviews increased → tag audiobook for review fetch
    └─ Do NOT fetch reviews here; defer to separate review-fetch cycle
    
5. REVIEW FETCH (separate scheduled run, triggered by tags from step 4B)
   For each tagged audiobook:
     GET https://{region}/1.0/catalog/products/{ASIN}/reviews/?sort_by=MostRecent&num_results=15&page={n}
     
     For each review returned:
       └─ If review_id not already stored → create new review record
          └─ Evaluate against each tracker user's notification thresholds
          └─ Tag with <user>-new or <user>-hide visibility terms

6. SEARCH (on-demand, user-triggered)
   GET https://{region}/1.0/catalog/products?response_groups=product_desc,contributors,rating,product_attrs,product_extended_attrs,media&products_sort_by={sort}&{search_type}={term}&page=0&num_results=50&image_sizes=100,500
   
   └─ For each result:
      └─ If ASIN not in local DB → create new audiobook record with full details
      └─ Create/update author and narrator taxonomy terms
      └─ Download and attach cover image
```

---

## 10. Glossary

| Term | Definition |
|---|---|
| **ASIN** | Amazon Standard Identification Number — a 10-character alphanumeric string uniquely identifying an audiobook product on Audible |
| **Aspect** | One of three rating dimensions: `overall`, `story`, `performance`. Each has its own star distribution and average. |
| **Response Groups** | Comma-separated list of data sections to include in search results (e.g., `product_desc,contributors,rating`) |
| **Guided Review** | A structured review where the customer answers preset questions instead of writing freeform text |
| **Freeform Review** | A standard text review with no structured Q&A component |
| **Region Code** | Two-letter identifier (`US`, `UK`) determining which Audible marketplace endpoint to use |
| **`narratic_zeroed`** | Internal counter tracking consecutive API responses with zeroed-out rating data (error state) |
