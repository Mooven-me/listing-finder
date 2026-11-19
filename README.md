# Listing Finder — AI Second-Hand Deal Intelligence Radar

**Listing Finder** is a Symfony 8.1 / FrankenPHP application that discovers, scrapes, and evaluates second-hand and refurbished marketplace listings (**LeBonCoin**, **Vinted**, **BackMarket**) against any product specification or model description using local (**Ollama**) or OpenAI-compatible LLMs.

---

## Core Capabilities

1. **Domain-Agnostic AI Search Expansion (`TopicExpansionAgent`)**:
   - Describe any item in a single unified **Product Specification** box — whether an exact model (e.g. `"canon printer ax380"`) or a detailed multi-sentence functional specification.
   - The AI automatically expands your description into concise, high-recall 2–3 word marketplace search queries (seller synonyms, French & English phrasings, exact model-code preservation, and automatic 2-word anchors for strict-AND search engines). You can let the AI choose the optimal number of search queries (`Auto`) or specify an exact count (`1` to `15`).
2. **Multi-Marketplace Relevance Search, Multi-Page Crawling & Full Ad Detail Scraping (`LeBonCoinProvider`, `VintedProvider`, `BackMarketProvider`)**:
   - Uses each marketplace's native **relevance ranking** by default (matching what you see when typing directly in the site's search bar), with optional sort overrides (`newest`, `price_asc`).
   - Supports **Unlimited multi-page crawling** (`Max Ads / Query` left blank in UI or `--unlimited` in CLI) to crawl across pages up to a configurable safety cap (`LISTING_PROVIDER_MAX_UNLIMITED_PAGES=15`).
   - Lazily fetches each listing's individual detail page once before AI evaluation (extracting full LeBonCoin seller descriptions, categories, brands, and structured attributes, plus full Vinted seller descriptions) so the AI never evaluates blind from a title alone.
   - **DRY self-describing Per-Provider Options**:
     - **LeBonCoin**: `Allow Nationwide Shipping` (default on via `shipping_type=...`), `Allow Near Me` (city/postal code geocoded via `api-adresse.data.gouv.fr` + configurable radius km; enabling BOTH runs a deduplicated **UNION** of shippable ads nationwide + local pickup ads near you), `Match Keywords in Title Only`, `Seller Type` (`all`/`private`/`pro`), `Search Ranking`, and `Min Price`.
     - **Vinted**: `Search Ranking` (`relevance`/`newest_first`/`price_low_to_high`), `Minimum Condition` (`status_ids[]`), and `Min Price`.
   - Asynchronous fetching via **Symfony Messenger** with deduplication (`uniq_listing_search_provider_external_id`) and concurrency safety (`symfony/lock`).
3. **Evidence-Grounded 5-Tier AI Appraisal + SearXNG Spec Enrichment (`ListingEvaluatorAgent`, `SearxngProductSpecService`)**:
   - Evaluates each listing using **reasoning-first structured JSON** on **Relevance** (45%), **Price vs. Target Budget** (25%), **Working Condition** (20%), and **Product Age** (10%), with automatic **Vision AI** capability detection via Ollama `/api/show` (automatically disabled and greyed out in the UI if the configured model is text-only).
   - Optionally queries a self-hosted **SearXNG** instance (`SEARXNG_BASE_URL`, cached 7 days) whenever a listing title names a concrete product model code, injecting factual datasheet snippets into the appraisal so the AI verifies real product specs instead of guessing.
   - Enforces strict anti-hallucination guardrails: requires verified evidence for mandatory buyer requirements, caps wrong items/accessories (`<= 10`), broken/HS items (`<= 15`), missing/unverified mandatory specifications (`<= 45` / `<= 29`), and overpriced listings.
   - Classifies every listing into 5 tiers (`DealCategory`):
     - **MUST BUY (`90–100`)**: Rare, exact specification match, functional, at or below target price.
     - **EXTREMELY GOOD (`70–89`)**: Matches all key specifications, functional, strong price.
     - **GOOD (`50–69`)**: Matches main product and most specs, acceptable price/wear.
     - **BAD (`30–49`)**: Overpriced, missing secondary features, or 1 unverified mandatory spec.
     - **POOR (`0–29`)**: Wrong item/accessory only (`0–10`), defective/HS (`0–15`), or contradicted/missing mandatory specifications (`10–29`).
4. **30-Minute Incremental Cron Radar (`CronTask` + `symfony/scheduler`)**:
   - Automatically re-queries enabled marketplaces every 30 minutes (or custom interval) and only evaluates newly discovered ads (`isComputed = false`).
5. **Live Web Dashboard (`DashboardController` + Twig + Chart.js)**:
   - Real-time telemetry bar (`Total Ads`, `Evaluated`, `Queue`), live 1-second cron countdown timer, 5-tier KPI cards, 3 live-updating Chart.js graphs (*Score Tier Distribution*, *Provider Comparison*, *Score vs. Price Scatter Plot*), expandable sub-topic & provider telemetry matrix, server-side pagination, and one-click strict AI re-evaluation.

---

## Web Dashboard

Access the dashboard at `/` (e.g. `https://listing-finder.zt.home.arpa/` or `https://localhost/`):

- **New Search Request Modal**: Single large **Product Specification & Requirements** textarea, **Target Price (EUR)**, **AI Searches Count** (`Auto` or `1–15`), **Max Ads / Query** (leave blank for **Unlimited** multi-page crawl), **Cron Interval**, **Providers**, dynamic per-provider option cards (LeBonCoin shipping + near-me location/radius, title-only, seller type; Vinted sort, min condition, min price), and **Vision AI** toggle (automatically disabled/greyed out when the active Ollama model lacks vision support).
- **Filter & Sort Toolbar**:
  - Sort **By Category** (grouped into the 5 tiers) or **By Score** (`100 -> 0`).
  - Filter by a single tier (`Must Buy`, `Ext. Good`, `Good`, `Bad`, `Poor`), provider (`LeBonCoin`, `Vinted`), AI sub-topic pill, keyword search (`q`), max price (`maxPrice`), or functional items only (`onlyWorking`).
  - Switch between **Cards View** and **Compact Table View** with configurable pagination (`10`, `20`, `50`, `100` per page).
- **Live Status API**: `GET /api/dashboard/status/{id}` returns real-time JSON telemetry and chart datasets polled every 5 seconds.

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
| `AI_PROVIDER` | `ollama` | `ollama` or `openai` (OpenAI-compatible `/v1/chat/completions`) |
| `AI_BASE_URL` | `http://host.docker.internal:11434` | Base URL of Ollama or OpenAI server |
| `AI_MODEL` | `lfm2.5:8b` | Text model for topic expansion & listing evaluation |
| `AI_VISION_MODEL` | `lfm2.5:8b` | Vision model when `enableImages` is enabled (auto-probed via Ollama `/api/show`) |
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
