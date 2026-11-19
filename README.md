# Listing Finder — AI Second-Hand Deal Intelligence Radar

**Listing Finder** is a Symfony 8.1 / FrankenPHP application that discovers, scrapes, and evaluates second-hand and refurbished marketplace listings (**LeBonCoin**, **Vinted**, **BackMarket**) against any product specification or model description using local (**Ollama**) or OpenAI-compatible LLMs.

---

## Core Capabilities

1. **Domain-Agnostic AI Search Expansion (`TopicExpansionAgent`)**:
   - Describe any item in a single unified **Product Specification** box — whether an exact model (e.g. `"canon printer ax380"`) or a detailed multi-sentence functional specification.
   - The AI extracts the core product noun (`core_product_noun`) and expands your description into concise, high-recall 2–3 word marketplace search queries anchored on that core product (preventing bare spec-token queries that flood results with accessories/modules). You can let the AI choose the optimal number of search queries (`Auto`) or specify an exact count (`1` to `15`).
2. **Multi-Marketplace Relevance Search, Polite Pacing, Sold-Ad Exclusion & Safe Availability Checks (`LeBonCoinProvider`, `VintedProvider`, `BackMarketProvider`, `ProviderHealthService`)**:
   - Uses each marketplace's native **relevance ranking** by default (matching what you see when typing directly in the site's search bar), with optional sort overrides (`newest`, `price_asc`).
   - Built-in polite pacing (`2.5s` LeBonCoin, `1.0s` Vinted) and an automatic **15-minute cooldown circuit breaker** on HTTP `403`/`429` responses (surfaced in a live dashboard warning banner) so your IP is never flooded.
   - **Pre-checked `Exclude Sold / Pending Ads`** option in LeBonCoin and Vinted skips pending/sold/reserved listings at crawl time and hides sold ads in the dashboard by default (with an optional `"Include Sold"` toggle). A safe rate-limited **Check Availability** button re-verifies only top unsold recommended deals (`score >= 50`, max 50/run) and aborts immediately on any `403`/`429`.
   - Supports **Unlimited multi-page crawling** (`Max Ads / Query` left blank in UI or `--unlimited` in CLI) to crawl across pages up to a configurable safety cap (`LISTING_PROVIDER_MAX_UNLIMITED_PAGES`).
   - Lazily fetches each surviving listing's individual detail page once before Stage-2 AI evaluation (after Stage-1 rejects accessories/wrong categories) so the AI never evaluates blind from a title alone.
   - **DRY self-describing Per-Provider Options**:
     - **LeBonCoin**: `Exclude Sold / Pending Ads` (pre-checked), `Allow Nationwide Shipping` (default on via `shipping_type=...`), `Allow Near Me` (city/postal code geocoded via `api-adresse.data.gouv.fr` + configurable radius km; enabling BOTH runs a deduplicated **UNION** of shippable ads nationwide + local pickup ads near you), `Match Keywords in Title Only`, `Seller Type` (`all`/`private`/`pro`), `Search Ranking`, and `Min Price`.
     - **Vinted**: `Exclude Sold / Reserved Items` (pre-checked), `Search Ranking` (`relevance`/`newest_first`/`price_low_to_high`), `Minimum Condition` (`status_ids[]`), and `Min Price`.
   - Asynchronous fetching via **Symfony Messenger** with deduplication (`uniq_listing_search_provider_external_id`) and concurrency safety (`symfony/lock`).
3. **Two-Stage Evidence-Grounded 5-Tier AI Appraisal + SearXNG Spec Enrichment (`ListingEvaluatorAgent`, `SearxngProductSpecService`)**:
   - **Stage-1 Product-Type Gatekeeper**: Runs before detail-page scraping and SearXNG lookup to immediately reject accessories, cables, modules, spare parts, or wrong product categories at `<= 10/100 (POOR)` — saving marketplace requests, SearXNG queries, and LLM tokens.
   - **Stage-2 Reasoning-First Appraisal**: Evaluates each valid listing on **Relevance** (45%), **Price vs. Target Budget** (25%), **Working Condition** (20%), and **Product Age** (10%), supporting local **Ollama** or OpenAI-compatible cloud endpoints (such as **Groq** free tier with `openai/gpt-oss-120b`, automatic request pacing via `AI_MIN_REQUEST_INTERVAL_MS`, and HTTP 429 `Retry-After` backoff). Automatically disables and greys out **Vision AI** in the UI when unsupported (`AI_VISION_ENABLED=auto|true|false`).
   - Optionally queries a self-hosted **SearXNG** instance (`SEARXNG_BASE_URL`, configured with `bing` + `duckduckgo`, 2s polite throttle, 7-day positive cache & 24h negative cache) whenever a listing title names a concrete product model code, injecting factual datasheet snippets into the appraisal.
   - Enforces strict anti-hallucination guardrails: handles conditional/alternative buyer specifications (`"if X then Y, otherwise Z"`), requires verified evidence for mandatory buyer requirements, caps wrong items/accessories (`<= 10`), broken/HS items (`<= 15`), missing/unverified mandatory specifications (`<= 45` / `<= 29`), and overpriced listings.
   - Classifies every listing into 5 tiers (`DealCategory`):
     - **MUST BUY (`90–100`)**: Rare, exact specification match, functional, at or below target price.
     - **EXTREMELY GOOD (`70–89`)**: Matches all key specifications, functional, strong price.
     - **GOOD (`50–69`)**: Matches main product and most specs, acceptable price/wear.
     - **BAD (`30–49`)**: Overpriced, missing secondary features, or 1 unverified mandatory spec.
     - **POOR (`0–29`)**: Wrong item/accessory only (`0–10`), defective/HS (`0–15`), or contradicted/missing mandatory specifications (`10–29`).
4. **30-Minute Incremental Cron Radar & "New Since Last Visit" Tracking (`CronTask` + `symfony/scheduler`)**:
   - Automatically re-queries enabled marketplaces every 30 minutes (or custom interval) and only evaluates newly discovered ads (`isComputed = false`).
   - Tracks `SearchRequest::$lastViewedAt` so returning to the dashboard immediately highlights newly discovered deals (`NEW` badge, `New Since Last Visit` summary banner, `Show New Only` filter, `Mark All Seen` button, and a **Cron Discoveries Timeline** stacked bar chart).
5. **Live Web Dashboard (`DashboardController` + Twig + Chart.js)**:
   - Real-time telemetry bar (`Total Ads`, `Evaluated`, `Queue`), provider health/cooldown banner, live 1-second cron countdown timer, 5-tier KPI cards, **4 live-updating Chart.js graphs** (*Score Tier Distribution*, *Provider Comparison*, *Score vs. Price Scatter Plot*, *Cron Discoveries Timeline*), expandable sub-topic & provider telemetry matrix, server-side pagination, one-click `Check Availability`, and one-click strict AI re-evaluation.

---

## Web Dashboard

Access the dashboard at `/` (e.g. `https://listing-finder.zt.home.arpa/` or `https://localhost/`):

- **New Search Request Modal**: Single large **Product Specification & Requirements** textarea, **Target Price (EUR)**, **AI Searches Count** (`Auto` or `1–15`), **Max Ads / Query** (leave blank for **Unlimited** multi-page crawl), **Cron Interval**, **Providers**, dynamic per-provider option cards (including pre-checked `Exclude Sold / Pending Ads`, LeBonCoin shipping + near-me location/radius, title-only, seller type; Vinted sort, min condition, min price), and **Vision AI** toggle (automatically disabled/greyed out when the active model lacks vision support).
- **Filter & Sort Toolbar**:
  - Sort **By Category** (grouped into the 5 tiers), **By Score** (`100 -> 0`), **Price: Low → High**, **Price: High → Low**, or **Newest First**.
  - Filter by a single tier (`Must Buy`, `Ext. Good`, `Good`, `Bad`, `Poor`), provider (`LeBonCoin`, `Vinted`), AI sub-topic pill, keyword search (`q`), max price (`maxPrice`), functional items only (`onlyWorking`), **New Only** (`onlyNew`), or **Include Sold** (`showSold`).
  - Switch between **Cards View** and **Compact Table View** with configurable pagination (`10`, `20`, `50`, `100` per page).
- **Live Status API**: `GET /api/dashboard/status/{id}` returns real-time JSON telemetry, unseen counts, provider health status, and all 4 chart datasets polled every 5 seconds.

---

## CLI Commands

```bash
# 1. Start a new search and register a 30-minute recurring cron task
#    (Omit --sub-topics to let the AI decide the number of search queries, or pass -s N)
#    (Use --unlimited / -u to crawl all pages, and -o provider.key=value for provider options)
php bin/console app:search:start --price=50 --unlimited -o leboncoin.allow_shipping=true -o leboncoin.allow_near_me=true -o leboncoin.location=Lyon -o leboncoin.radius_km=30 "canon printer ax380 in working condition"
php bin/console app:search:start --price=120 --sub-topics=6 --limit=25 "compact mirrorless camera 4k with lens"

# 2. Display evaluated results in the terminal
#    Sort by category (default) or by score (--by-score)
php bin/console app:search:results
php bin/console app:search:results 1 --by-score
php bin/console app:search:results 1 --by-category --all

#    Filter by a single category (must_buy | extremely_good | good | bad | poor)
php bin/console app:search:results 1 must_buy
php bin/console app:search:results extremely_good

# 3. Manually trigger due (or all) recurring cron tasks
php bin/console app:cron:run
php bin/console app:cron:run --force

# 4. Stop and remove the recurring cron task for a search
php bin/console app:search:stop 1

# 5. Run the background worker (consumes both the 30-min scheduler and async queue)
php bin/console messenger:consume scheduler_default async --time-limit=3600 --memory-limit=256M -vv
```

---

## Environment Configuration (`.env` / `.env.local` / `.env.prod`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `DATABASE_URL` | `postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8` | PostgreSQL connection URL |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Async queue transport |
| `LOCK_DSN` | `flock` | Symfony Lock store |
| `AI_PROVIDER` | `ollama` | `ollama` or `openai` (OpenAI-compatible `/v1/chat/completions`, e.g., Groq) |
| `AI_BASE_URL` | `http://host.docker.internal:11434` | Base URL of Ollama or OpenAI-compatible server (`https://api.groq.com/openai/v1` for Groq) |
| `AI_API_KEY` | *(empty)* | API key for OpenAI-compatible providers (store privately in `.env.local` or k8s Secret, never commit) |
| `AI_MODEL` | `lfm2.5:8b` | Text model for topic expansion & listing evaluation (e.g. `openai/gpt-oss-120b` on Groq) |
| `AI_VISION_MODEL` | `lfm2.5:8b` | Vision model when `enableImages` is enabled |
| `AI_VISION_ENABLED` | `auto` | `auto` (probes Ollama `/api/show`, defaults off for OpenAI), `true`, or `false` |
| `AI_MIN_REQUEST_INTERVAL_MS` | `0` | Minimum milliseconds between LLM requests (e.g. `9000` for Groq free-tier TPM/RPM pacing) |
| `AI_TIMEOUT` | `120` | LLM request timeout in seconds |
| `AI_NUM_CTX` | `8192` | Ollama runtime context window (`options.num_ctx`, no custom Modelfile needed) |
| `SEARXNG_BASE_URL` | `http://searxng:8080` | Internal SearXNG URL for model datasheet/spec lookups (leave empty to disable) |
| `SEARXNG_TIMEOUT` | `8` | SearXNG HTTP lookup timeout in seconds |
| `LISTING_PROVIDERS_ENABLED` | `leboncoin,vinted,backmarket` | Default enabled marketplace providers |
| `LISTING_PROVIDER_FALLBACK_TO_MOCK` | `false` (prod) / `true` (dev/test) | Whether HTTP failures fall back to `MockListingProvider` |
| `LISTING_PROVIDER_MAX_UNLIMITED_PAGES` | `15` | Safety page cap per query when crawling in Unlimited mode |

---

## Deployment

### 1. Local Development (Docker Compose)
In development, `compose.yaml` + `compose.override.yaml` automatically start FrankenPHP (`php`), the Messenger consumer (`worker`), `searxng`, and a local PostgreSQL container (`database`):

```bash
docker compose up -d
```

### 2. Production with Docker Compose (External Shared Database)
`compose.yaml` + `compose.prod.yaml` run `php`, `worker`, and `searxng` (no local database container is created), reading `.env.prod`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml build
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

### 3. Production on Kubernetes (k3s + Traefik)
See [k8s/README.md](file:///home/mooven/projet/listing-finder/k8s/README.md) for full details (includes in-cluster `searxng` deployment):

```bash
docker build --target frankenphp_prod -t listing-finder:prod .
docker save listing-finder:prod | sudo k3s ctr images import -
kubectl apply -k k8s/
kubectl -n listing-finder rollout restart deployment/listing-finder-web deployment/listing-finder-worker
kubectl -n listing-finder rollout status deployment/listing-finder-web
kubectl -n listing-finder rollout status deployment/listing-finder-worker
```
