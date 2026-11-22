# Listing Finder — AI Second-Hand Deal Intelligence Radar

**Listing Finder** is a domain-agnostic AI second-hand deal radar built on **Symfony 8.1**, **PHP 8.5**, **FrankenPHP**, **PostgreSQL 16**, **Symfony Messenger & Scheduler**, **SearXNG**, and **Twig + Chart.js**. It continuously discovers, scrapes, enriches, and evaluates second-hand and refurbished marketplace listings (**LeBonCoin**, **Vinted**, **BackMarket**) against any product specification or model description using local (**Ollama**) or OpenAI-compatible (**Groq**, **OpenAI**, **vLLM**) language models.

![Listing Finder Main Dashboard](docs/listing-finder_screen.png)

---

## Table of Contents

1. [Agentic AI Architecture & Local GPU Sizing](#0-agentic-ai-architecture--local-gpu-sizing)
   - [0.1 Autonomous Multi-Stage Agent Pipeline](#01-autonomous-multi-stage-agent-pipeline)
   - [0.2 Why Local AI Execution Matters for 24/7 Deal Hunting](#02-why-local-ai-execution-matters-for-247-deal-hunting)
   - [0.3 Recommended Local GPU Hardware & VRAM Sizing](#03-recommended-local-gpu-hardware--vram-sizing)
2. [How to Create and Install the Project](#1-how-to-create-and-install-the-project)
   - [1.1 Prerequisites](#11-prerequisites)
   - [1.2 Step 1: Clone the Repository](#12-step-1-clone-the-repository)
   - [1.3 Step 2: Configure the AI Provider](#13-step-2-configure-the-ai-provider)
   - [1.4 Step 3: Build and Start the Containers](#14-step-3-build-and-start-the-containers)
   - [1.5 Step 4: Verify the Stack and Access the Dashboard](#15-step-4-verify-the-stack-and-access-the-dashboard)
   - [1.6 Production Deployment Topologies](#16-production-deployment-topologies)
   - [1.7 Environment Variables Reference](#17-environment-variables-reference)
3. [How to Use the Software (Deep Tutorial)](#2-how-to-use-the-software-deep-tutorial)
   - [2.1 Creating a Search Request](#21-creating-a-search-request)
   - [2.2 How the Two-Stage AI Pipeline Evaluates Listings](#22-how-the-two-stage-ai-pipeline-evaluates-listings)
   - [2.3 Navigating the Web Dashboard and Visual Analytics](#23-navigating-the-web-dashboard-and-visual-analytics)
   - [2.4 Browsing, Filtering, and Managing Deals](#24-browsing-filtering-and-managing-deals)
   - [2.5 Managing Recurring Cron Monitoring and Availability Checks](#25-managing-recurring-cron-monitoring-and-availability-checks)
   - [2.6 Command-Line Interface (CLI) Reference](#26-command-line-interface-cli-reference)

---

## 0. Agentic AI Architecture & Local GPU Sizing

### 0.1 Autonomous Multi-Stage Agent Pipeline

Listing Finder operates as a continuous, domain-agnostic autonomous agent loop. Rather than sending raw marketplace pages into a single monolithic prompt, it orchestrates specialized agent stages, deterministic anti-hallucination guardrails, lazy detail-page enrichment, and self-hosted web datasheet verification (**SearXNG**):

```mermaid
flowchart TD
    UserSpec["Buyer Product Specification & Target Budget (EUR)"] --> TopicAgent["Stage 0: TopicExpansionAgent<br/>Core-Noun Extraction & High-Recall Strict-AND Queries"]
    TopicAgent --> Dispatcher["SearchDispatcherService & 30-Min Symfony Scheduler"]
    Dispatcher --> Providers["Marketplace Providers (LeBonCoin, Vinted, BackMarket)<br/>Polite Pacing & 15-Min Anti-Bot Circuit Breaker"]
    Providers --> LockDedup["PostgreSQL Upsert & Symfony Lock Deduplication"]
    LockDedup --> Stage1["Stage 1: Core-Product Gatekeeper (Pre-Scrape Filter)<br/>1. Zero-LLM Multilingual Accessory Guard<br/>2. Fast Micro-LLM Product-Type Classification"]
    Stage1 -- "Accessory / Wrong Category" --> FastReject["Immediate POOR Rejection (<= 10/100)<br/>0 Detail HTTP Requests & 0 SearXNG Calls Wasted"]
    Stage1 -- "Core Product Confirmed" --> Enrichment["Lazy Detail-Page Scraping & Sold/Pending Detection"]
    Enrichment -- "Active Listing" --> SearXNG["Self-Hosted SearXNG Datasheet RAG<br/>Model Code Specification Lookup (7-Day Cache)"]
    SearXNG --> Stage2["Stage 2: Reasoning-First & Multimodal Vision Evaluator<br/>Strict JSON Schema (Reasoning Emitted First)"]
    Stage2 --> Calibration["Deterministic Post-LLM Calibration Guardrails<br/>Spec Verification, Defective Caps & Price-Ratio Ceilings"]
    Calibration --> Dashboard["5-Tier Deal Classification & Real-Time Chart.js Dashboard"]
```

### 0.2 Why Local AI Execution Matters for 24/7 Deal Hunting

Running an autonomous marketplace radar places unique demands on LLM inference that make **Local AI (Ollama / vLLM on a dedicated GPU)** vastly superior to rate-limited cloud APIs:

1. **High Inference Volume Without Rate-Limit Bottlenecks**:
   - A single multi-page search across 6 to 8 generated sub-topics typically discovers **1,000 to 1,500 unique listings**, followed by recurring incremental cron sweeps every 30 minutes.
   - On a free-tier cloud API limited to `30 RPM` / `8,000 TPM`, outbound requests must be throttled to `AI_MIN_REQUEST_INTERVAL_MS=9000` (1 request every 9 seconds), meaning an initial 1,200-listing evaluation wave takes **over 3.5 hours**.
   - On a **Local GPU with Ollama** (`AI_MIN_REQUEST_INTERVAL_MS=0`), the agent runs completely unthrottled at full GPU throughput with **zero per-token API cost**, evaluating the same wave up to **15x–30x faster**.
2. **Multimodal Vision AI Inspection on Listing Photos**:
   - Second-hand listings frequently omit crucial visual details from the text description (physical damage, missing connectors, empty retail boxes, or a model sticker that contradicts the title).
   - Local multimodal vision-language models (`gemma3`, `qwen2.5-vl`, `llama3.2-vision`, `lfm2.5`) inspect listing thumbnails directly on your GPU when `AI_VISION_ENABLED=auto`.
3. **100% Self-Hosted Data Sovereignty**:
   - The entire intelligence stack — **Ollama** (local LLM/VLM inference), **SearXNG** (self-hosted meta-search for technical datasheets), **PostgreSQL 16**, and **FrankenPHP** — runs locally on your hardware without exposing search habits or purchasing thresholds to external third parties.

### 0.3 Recommended Local GPU Hardware & VRAM Sizing

Because the worker evaluates listings continuously alongside a self-hosted SearXNG datasheet RAG (`AI_NUM_CTX=8192` to `32768`), available **GPU VRAM** directly determines model reasoning depth, multimodal Vision AI capability, and parallel worker concurrency:

| Hardware Tier | Available VRAM | Recommended Local Models (Ollama) | Context Window (`AI_NUM_CTX`) | Vision AI Photo Inspection | Optimal Worker Concurrency & Workload Fit |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Entry Local GPU** | `8 GB – 12 GB VRAM` | `lfm2.5:8b`, `qwen2.5:7b`, `llama3.1:8b` (`Q4_K_M`) | `8192` | Optional (lightweight 7B/8B VLM) | **1 Worker**: Reliable local text & datasheet evaluation with zero cloud latency or rate limits. |
| **High-Performance Developer GPU** | `16 GB VRAM` | `gemma3:12b`, `qwen2.5:14b`, `qwen2.5-vl:7b`, `llama3.2-vision:11b` | `16384` | **Enabled (`auto` / `true`)** | **1–2 Workers**: High-speed token generation with stronger 12B–14B multi-branch spec reasoning and live Vision AI photo inspection. |
| **Workstation / High-End AI GPU** | `24 GB – 32 GB+ VRAM` | `qwen2.5-vl:32b`, `gemma3:27b`, `qwen2.5:32b`, `llama3.3:70b-iq4` | `32768` | **Enabled (High-Res Multimodal)** | **2–4 Parallel Workers**: Maximum specification verification accuracy on complex technical datasheets and simultaneous multi-search processing. |

> **Built-in Pipeline & Hardware Benchmark**: You can measure your local GPU's end-to-end latency across Stage 0, Stage 1, and Stage 2 and view projected 1,000-listing throughput at any time by running:
> ```bash
> docker compose exec php php bin/console app:ai:benchmark
> ```

---

## 1. How to Create and Install the Project

This section walks you through setting up **Listing Finder** from scratch on your machine using Docker Compose. The default development stack is self-contained and launches the FrankenPHP web server, the background Messenger/Scheduler worker, a PostgreSQL 16 database, and a self-hosted SearXNG specification lookup engine.

### 1.1 Prerequisites

Before starting, make sure the following tools are installed on your host machine:

- **Docker Engine** and **Docker Compose v2** (`docker compose version`)
- **Git**
- **An LLM Backend** (choose one):
  - **Local AI (Ollama)**: Installed on your host machine (`http://localhost:11434`) or accessible on your local network.
  - **Cloud OpenAI-Compatible API**: An API endpoint and key from a provider such as **Groq** (`https://api.groq.com/openai/v1`), OpenAI, Together AI, or a self-hosted vLLM/LiteLLM server.

### 1.2 Step 1: Clone the Repository

Clone the repository and move into the project directory:

```bash
git clone <your-repository-url> listing-finder
cd listing-finder
```

### 1.3 Step 2: Configure the AI Provider

Default configuration values live in [.env](.env). To customize your installation without modifying committed files, create an untracked `.env.local` file at the root of the project:

```bash
cp .env .env.local
```

Open `.env.local` and configure either **Option A (Local Ollama)** or **Option B (OpenAI-Compatible Cloud API such as Groq)**.

#### Option A: Local AI with Ollama (Default)

1. Ensure Ollama is running on your host machine and pull an instruction-tuned model (for example `lfm2.5:8b`, `qwen2.5:7b`, or `llama3.1:8b`):

   ```bash
   ollama pull lfm2.5:8b
   ```

2. Set the following variables in `.env.local`:

   ```dotenv
   AI_PROVIDER=ollama
   AI_BASE_URL=http://host.docker.internal:11434
   AI_MODEL=lfm2.5:8b
   AI_VISION_MODEL=lfm2.5:8b
   AI_VISION_ENABLED=auto
   AI_NUM_CTX=8192
   AI_MIN_REQUEST_INTERVAL_MS=0
   ```

   > **Note on Linux Hosts**: `compose.yaml` and `compose.override.yaml` automatically map `host.docker.internal` to the Docker host gateway, allowing containers to reach Ollama on port `11434` as long as Ollama listens on `0.0.0.0:11434` (set `OLLAMA_HOST=0.0.0.0` in your Ollama service if needed).

#### Option B: Cloud OpenAI-Compatible API (Groq Free Tier Example)

If you prefer not to run a local GPU model, you can point Listing Finder at any OpenAI-compatible `/v1/chat/completions` endpoint. The client includes built-in cross-request pacing (`AI_MIN_REQUEST_INTERVAL_MS`) and automatic HTTP `429` `Retry-After` backoff:

```dotenv
AI_PROVIDER=openai
AI_BASE_URL=https://api.groq.com/openai/v1
AI_API_KEY=gsk_your_private_api_key_here
AI_MODEL=openai/gpt-oss-120b
AI_VISION_MODEL=openai/gpt-oss-120b
AI_VISION_ENABLED=false
AI_MIN_REQUEST_INTERVAL_MS=9000
```

> **Production Note**: To disable the synthetic mock fallback when a marketplace returns zero results or is rate-limited, set `LISTING_PROVIDER_FALLBACK_TO_MOCK=false` in `.env.local`.

### 1.4 Step 3: Build and Start the Containers

Build and start the full stack in detached mode:

```bash
docker compose up -d --build
```

When `docker compose up -d --build` runs, four services are created and wired together automatically:

1. **`php` (FrankenPHP Web Server)**:
   - Serves the web dashboard over HTTP (port `80`) and HTTPS (port `443`) using Caddy.
   - Runs `frankenphp/docker-entrypoint.sh` on boot, which automatically executes `composer install` (if `vendor/` is empty), waits for PostgreSQL readiness, and applies all Doctrine database migrations (`php bin/console doctrine:migrations:migrate --no-interaction`).
2. **`worker` (Symfony Messenger & Scheduler Consumer)**:
   - Continuously runs `php bin/console messenger:consume scheduler_default async --time-limit=3600 --memory-limit=256M -vv`.
   - Processes asynchronous marketplace crawl jobs (`FetchSubTopicListingsMessage`), two-stage AI appraisals (`EvaluateListingMessage`), availability verification jobs (`CheckSearchAvailabilityMessage`), and 60-second scheduler ticks (`RunDueCronTasksMessage`).
3. **`database` (PostgreSQL 16 Alpine)**:
   - Stores all `SearchRequest`, `Listing`, `ListingEvaluation`, `CronTask`, and Doctrine Messenger queue records in a persistent Docker volume (`database_data`).
4. **`searxng` (Self-Hosted Meta-Search Engine)**:
   - Runs `searxng/searxng:latest` mounted with [docker/searxng/settings.yml](docker/searxng/settings.yml) (JSON API enabled, `bing` and `duckduckgo` engines active) on `http://searxng:8080`.
   - Provides factual external product specification snippets to the AI evaluator whenever a marketplace listing mentions a concrete product model code.

### 1.5 Step 4: Verify the Stack and Access the Dashboard

1. Check that all four containers are running and healthy:

   ```bash
   docker compose ps
   ```

2. Follow the background worker logs to confirm that the `scheduler_default` and `async` queues are actively being consumed:

   ```bash
   docker compose logs -f worker
   ```

3. Open your browser and navigate to:

   ```text
   https://localhost
   ```

   *(Accept the self-signed local Caddy TLS certificate if prompted, or visit `http://localhost`.)*

### 1.6 Production Deployment Topologies

In addition to local development, the project supports two production deployment architectures:

#### A. Production Docker Compose (External Shared PostgreSQL Database)

Use [compose.yaml](compose.yaml) combined with [compose.prod.yaml](compose.prod.yaml) when deploying on a single server connected to an external PostgreSQL instance configured in `.env.prod`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml build
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

#### B. Production Kubernetes (k3s + Traefik)

The [k8s/](k8s/) folder contains Kustomize manifests for deploying the FrankenPHP web server, a single-replica Messenger worker (kept at 1 replica to prevent parallel IP bursts from triggering marketplace anti-bot blocks), and an internal SearXNG service in namespace `listing-finder`. See [k8s/README.md](k8s/README.md) for the complete guide:

```bash
# 1. Build the production image and import it into k3s containerd
docker build --target frankenphp_prod -t listing-finder:prod .
docker save listing-finder:prod | sudo k3s ctr images import -

# 2. Create namespace and secret (from k8s/secret.example.yaml)
kubectl apply -f k8s/namespace.yaml
cp k8s/secret.example.yaml k8s/secret.local.yaml
# Edit k8s/secret.local.yaml with your real DATABASE_URL, APP_SECRET, and AI_API_KEY
kubectl apply -f k8s/secret.local.yaml

# 3. Apply manifests and wait for rollout
kubectl apply -k k8s/
kubectl -n listing-finder rollout restart deployment/listing-finder-web deployment/listing-finder-worker
kubectl -n listing-finder rollout status deployment/listing-finder-web
kubectl -n listing-finder rollout status deployment/listing-finder-worker
```

### 1.7 Environment Variables Reference

| Variable | Default | Description |
| :--- | :--- | :--- |
| `APP_ENV` | `dev` | Symfony environment (`dev`, `prod`, `test`) |
| `DATABASE_URL` | `postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8` | PostgreSQL 16 connection string |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Async queue transport backed by Doctrine |
| `LOCK_DSN` | `flock` | Symfony Lock store used to prevent duplicate concurrent ad insertions |
| `AI_PROVIDER` | `ollama` | LLM driver: `ollama` (`/api/chat`) or `openai` (`/v1/chat/completions`) |
| `AI_BASE_URL` | `http://host.docker.internal:11434` | Base URL of the Ollama or OpenAI-compatible server |
| `AI_API_KEY` | *(empty)* | Bearer API key for `openai`-compatible endpoints (store in `.env.local` or k8s Secret) |
| `AI_MODEL` | `lfm2.5:8b` | Model name for topic expansion, Stage-1 gatekeeper, and Stage-2 appraisal |
| `AI_VISION_MODEL` | `lfm2.5:8b` | Model name used when Vision AI photo inspection is enabled |
| `AI_VISION_ENABLED` | `auto` | `auto` (probes Ollama `/api/show` capabilities; defaults to `false` for OpenAI), `true`, or `false` |
| `AI_MIN_REQUEST_INTERVAL_MS` | `0` | Minimum delay in milliseconds between outbound LLM calls (`9000` recommended for Groq free tier) |
| `AI_TIMEOUT` | `120` | HTTP timeout in seconds for each LLM request |
| `AI_NUM_CTX` | `8192` | Ollama runtime context window size (`options.num_ctx`) |
| `SEARXNG_BASE_URL` | `http://searxng:8080` | Internal SearXNG URL for model datasheet verification (leave empty to disable) |
| `SEARXNG_TIMEOUT` | `8` | HTTP timeout in seconds for SearXNG lookups |
| `LISTING_PROVIDERS_ENABLED` | `leboncoin,vinted,backmarket,mock` | Comma-separated list of active marketplace providers |
| `LISTING_PROVIDER_FALLBACK_TO_MOCK` | `true` (dev) / `false` (prod) | Whether HTTP/network errors fall back to synthetic fixture listings |
| `LISTING_PROVIDER_MAX_UNLIMITED_PAGES` | `15` (dev) / `5` (k8s prod) | Safety cap on the number of pages crawled per query in Unlimited mode |

---

## 2. How to Use the Software (Deep Tutorial)

Listing Finder is 100% domain-agnostic: it contains no hardcoded category rules and can hunt for any product — electronics, networking hardware, photography gear, musical instruments, clothing, furniture, bicycles, industrial tools, or collectibles.

### 2.1 Creating a Search Request

Click the **+ New** button in the top-left sidebar of the web dashboard to open the **New Search Request** modal.

![New Search Request Modal](docs/listing-finder_creation_screen.png)

#### 1. Writing an Effective Product Specification & Requirements

The **Product Specification & Requirements** textarea is the single source of truth for both search query generation and AI deal evaluation. You can write your specification in any language (French, English, etc.) using one of two styles:

- **Exact Product or Model Search**:
  - Example: `"Canon printer AX380 in working condition with power cable"`
- **Multi-Sentence Technical or Functional Specification**:
  - Example: `"Je voudrais une carte graphique avec minimum 16Go de VRAM"`
  - Example with conditional branches: `"Managed network switch with at least 8 Gigabit RJ45 ports and 2 SFP+ 10GbE cages; if PoE+ is included then 8 ports is fine, otherwise 16 ports minimum. Must be functional and rack-mountable."`

How the AI processes this field:
- **Core Product Anchor (`TopicExpansionAgent`)**: The AI extracts the core product noun (for example `carte graphique` or `switch`) and its direct synonyms (`GPU`), then generates concise 2–3 word search queries where every query is anchored on that product noun. This prevents bare technical tokens (like `"16Go"` or `"SFP+"` alone) from flooding your database with unrelated memory sticks, cables, or spare parts.
- **Conditional Requirement Handling (`ListingEvaluatorAgent`)**: If you write conditional logic (`"if X then Y, otherwise Z"` or `"si X alors Y, sinon Z"`), the evaluator checks whether the listing satisfies either valid branch rather than penalizing tokens from the unused branch.

#### 2. Configuring Search Parameters

Below the specification box, four numeric inputs control how the radar operates:

- **Target Price (EUR)**:
  - Your ideal budget for the item.
  - During marketplace crawling, providers fetch ads up to `1.5x` your target price so slightly above-budget listings with exceptional specifications are still evaluated (and scored accordingly against your budget).
- **AI Searches Count**:
  - Leave blank (`Auto (AI)`) to let the AI pick the optimal number of diverse search queries (typically `5` to `8` queries covering synonyms, model codes, and French/English phrasing).
  - Enter a number between `1` and `15` to force an exact number of generated sub-topics.
- **Max Ads / Query**:
  - Leave blank (`Unlimited`) to crawl across all available result pages for every generated sub-topic up to the server page safety cap (`LISTING_PROVIDER_MAX_UNLIMITED_PAGES`).
  - Enter a specific number (for example `25` or `50`) to limit how many listings are fetched per sub-topic on each provider.
- **Cron (Minutes)**:
  - Sets the recurring background monitoring interval (default `30` minutes, configurable from `5` to `1440` minutes). Every cycle, the radar re-queries the marketplaces and evaluates only newly posted listings.

#### 3. Selecting Providers and Per-Provider Options

Check or uncheck **BACKMARKET**, **LEBONCOIN**, and **VINTED** under **Providers**. Checking a provider reveals its dedicated settings panel:

##### LeBonCoin Options (9 Settings)

| Option | UI Control | Default | Behavior |
| :--- | :--- | :--- | :--- |
| **Search Ranking** (`sort`) | Select | `Relevance (Default)` | Uses LeBonCoin's native search bar relevance ranking by default. Can be switched to `Newest First` (`time`) or `Price: Low to High` (`price_asc`). |
| **Allow Nationwide Shipping** (`allow_shipping`) | Checkbox | Checked | Filters for listings offering nationwide parcel delivery across France (`mondial_relay`, `colissimo`, `chronopost`, `shop2shop`, `relais_colis`). |
| **Allow Near Me (Local Pickup)** (`allow_near_me`) | Checkbox | Unchecked | Enables local hand-delivery search around a French city or postal code. |
| **Location (City or Postal Code)** (`location`) | Text | *(empty)* | Shown when `Allow Near Me` is checked. Geocodes any French city or postcode (e.g. `Lyon`, `Vannes`, `75001`) via `api-adresse.data.gouv.fr` (cached for 7 days). |
| **Radius (km)** (`radius_km`) | Number | `30` | Shown when `Allow Near Me` is checked. Search radius in kilometers (`5` to `200` km) around the geocoded coordinates. |
| **Exclude Sold / Pending Ads** (`exclude_sold`) | Checkbox | Checked | Skips ads at crawl time that have `status !== 'active'` or an in-progress/completed transaction (`transaction_status` in `pending`, `sold`, `reserved`, `completed`). |
| **Match Keywords in Title Only** (`search_in_title`) | Checkbox | Unchecked | Passes `search_in=subject` to LeBonCoin so keywords must appear in the listing title rather than the body text. |
| **Seller Type** (`owner_type`) | Select | `All Sellers` | Filter between `All Sellers` (`all`), `Private Individuals Only` (`private`), or `Professionals Only` (`pro`). |
| **Min Price (EUR)** (`min_price`) | Number | *(empty)* | Skips symbolic 1 EUR ads or low-cost accessories below the specified floor price. |

> **Combining Nationwide Shipping and Local Pickup (Deduplicated Union)**:
> On LeBonCoin, if you check **both** `Allow Nationwide Shipping` and `Allow Near Me` (with a city and radius), Listing Finder automatically executes two queries per sub-topic — one for shippable ads across France and one for local hand-delivery ads within your radius — and merges their **deduplicated union** by external listing ID. If you uncheck `Allow Nationwide Shipping` and only keep `Allow Near Me`, it restricts results strictly to your local radius.

##### Vinted Options (4 Settings)

| Option | UI Control | Default | Behavior |
| :--- | :--- | :--- | :--- |
| **Search Ranking** (`sort`) | Select | `Relevance (Default)` | Matches Vinted catalog relevance (`relevance`), or sorts by `Newest First` (`newest_first`) / `Price: Low to High` (`price_low_to_high`). |
| **Minimum Condition** (`min_condition`) | Select | `All Conditions` | Filters Vinted `status_ids[]`: `All Conditions` (`all`), `Good or Better` (`good_plus`), `Very Good or Better` (`very_good_plus`), or `New Only` (`new_only`). |
| **Exclude Sold / Reserved Ads** (`exclude_sold`) | Checkbox | Checked | Skips items flagged as sold, closed, reserved, or unbuyable. |
| **Min Price (EUR)** (`min_price`) | Number | *(empty)* | Sets `price_from` to filter out low-priced accessories or boxes. |

#### 4. Vision AI Photo Inspection

At the bottom of the modal, the **Enable Vision AI photo inspection** checkbox controls whether listing thumbnail photos are downloaded, converted to Base64, and sent to a multimodal vision model during Stage-2 evaluation:
- When `AI_VISION_ENABLED=auto`, Listing Finder automatically queries your LLM backend to verify whether the configured model supports vision.
- If the active model is text-only (such as `openai/gpt-oss-120b` shown in the screenshot), the checkbox is automatically disabled and greyed out with an explanatory note, and the backend skips downloading listing images to save bandwidth.

---

### 2.2 How the Two-Stage AI Pipeline Evaluates Listings

Once you click **Start Search**, `SearchDispatcherService` queues one `FetchSubTopicListingsMessage` per `(subTopic, provider)` combination. Each discovered ad is deduplicated by `(search_request_id, provider, external_id)` under a mutex lock (`symfony/lock`) and queued for a strict two-stage appraisal (`EvaluateListingMessage`).

#### Stage 1: Core Product-Type Gatekeeper (Pre-Scrape Filter)

Before making extra HTTP requests to fetch an ad's individual detail page or querying SearXNG, `EvaluateListingHandler` runs **Stage 1 (`ListingEvaluatorAgent::checkCoreProductGatekeeper()`)** on the listing title:

1. **Zero-LLM Universal Accessory Guard**: Immediately checks whether the title is a standalone accessory, component, cable, adapter, charger, protective case, empty box, optical transceiver module, rack ear/bracket, or thermal pad/waterblock — unless your specification explicitly asked for that accessory type.
2. **Compact LLM Product-Type Check**: Compares `buyer_wants_item_type` against `listing_sells_item_type`.
3. **Fast Rejection**: If the listing sells the wrong product category or a spare part/accessory, Stage 1 immediately records a `POOR` evaluation (`<= 10/100`) and marks the listing as computed. No detail-page request, no SearXNG query, and no Stage-2 LLM tokens are wasted.

#### Lazy Detail-Page Enrichment and SearXNG Specification Lookup

If a listing passes Stage 1:

1. **Detail-Page Scraping (`fetchListingDetails()`)**:
   - The worker fetches the listing's full web page once (respecting polite rate limits: `2.5s` between LeBonCoin requests, `1.0s` between Vinted requests).
   - On LeBonCoin, it extracts the full seller description (`ad.body`), category, brand, and structured attributes (`condition`, `model`, `memory`, etc.) from `<script id="__NEXT_DATA__">`.
   - On Vinted, it extracts the full item description and brand from the SSR payload.
   - **Live Sold Detection**: If the detail page returns HTTP `404`/`410` or indicates the item is sold, reserved, or has a pending transaction, the listing is immediately flagged `isSold = true` and skipped.
2. **Domain-Agnostic Datasheet Verification (`SearxngProductSpecService`)**:
   - If the listing title contains an alphanumeric model identifier (such as `RTX 3080`, `CRS305-1G-4S+IN`, `AX380`), Listing Finder queries the internal SearXNG service (`bing` + `duckduckgo`, throttled to 1 request per 2 seconds, cached for 7 days).
   - Verified external specification snippets are injected into the Stage-2 prompt under `Verified External Product Reference (SearXNG)` so the LLM can verify technical capabilities (such as VRAM capacity, port counts, or release year) from factual reference data when the seller wrote a short description.

#### Stage 2: Reasoning-First Appraisal and Anti-Hallucination Guardrails

In Stage 2, the LLM evaluates the enriched listing using a strict JSON Schema where `reasoning` is generated **first**, followed by four sub-scores (`0–100`):

- **Relevance Score (45% weight)**: How closely the item matches every mandatory technical requirement in your specification.
- **Price Score (25% weight)**: How attractive the listing price is relative to your `Target Price (EUR)`.
- **Working Condition Score (20% weight)**: Whether the item is confirmed functional (`isWorking = true`), defective/for-parts (`isWorking = false`), or unconfirmed (`null`), combined with physical wear.
- **Product Age Score (10% weight)**: Estimated age or hardware generation (`estimatedAge`).

After the LLM responds, deterministic post-LLM calibration guardrails (`enforceStrictCalibration`) enforce strict score ceilings:

- **Wrong Product Type or Accessory Only**: Score capped at `<= 10/100 (POOR)`.
- **Defective, Broken, Non-Working, or "HS / Pour Pieces"**: Score capped at `<= 15/100 (POOR)`.
- **Contradicted Mandatory Spec or 2+ Unverified Mandatory Specs**: Score capped at `<= 29/100 (POOR)`.
- **1 Unverified Mandatory Spec**: Score capped at `<= 45/100 (BAD)`.

#### The 5 Deal Classification Tiers (`DealCategory`)

| Tier | Score Range | Dashboard Badge | Meaning |
| :--- | :--- | :--- | :--- |
| **MUST BUY** | `90 – 100` | `MUST BUY` (Emerald) | Exact product and specification match, confirmed functional, and priced at or well below your target budget. |
| **EXTREMELY GOOD** | `70 – 89` | `EXTREMELY GOOD` (Sky Blue) | Matches all key specifications, functional condition, and strong price around or below your target budget. |
| **GOOD** | `50 – 69` | `GOOD` (Neutral Zinc) | Matches the main product and most specifications with an acceptable price or minor cosmetic wear. |
| **BAD** | `30 – 49` | `BAD` (Amber) | Overpriced relative to target budget, missing secondary features, worn condition, or 1 unverified mandatory spec. |
| **POOR** | `0 – 29` | `POOR` (Rose) | Wrong product type/accessory (`0–10`), defective/HS (`0–15`), or contradicts/lacks mandatory specifications (`10–29`). |

---

### 2.3 Navigating the Web Dashboard and Visual Analytics

The web dashboard updates continuously every 5 seconds via `GET /api/dashboard/status/{id}` without requiring page reloads.

![Listing Finder Main Dashboard](docs/listing-finder_screen.png)

#### 1. Left Sidebar and Live Global Telemetry

- **Header**: Displays the active LLM model name (for example `openai/gpt-oss-120b` or `lfm2.5:8b`) and the **+ New** search button.
- **Live Telemetry Panel**:
  - `TOTAL ADS`: Total deduplicated listings stored across all saved searches.
  - `EVALUATED`: Total listings that have completed AI appraisal.
  - `QUEUE`: Current number of pending jobs in the Symfony Messenger queue (highlighted in amber while jobs are processing, emerald when `0`).
  - `Synced` indicator: Pulses on every 5-second telemetry refresh.
- **Saved Searches List**:
  - Lists every configured `SearchRequest` with its ID, title, target price in EUR, evaluated/total ad ratio (`1203/1208`), high-value deal badges (`MB` for Must Buy count, `EG` for Extremely Good count), a green status dot when the recurring cron task is active, and a completion progress bar.
- **Sidebar Footer**: Shows the active AI engine (`OPENAI` or `OLLAMA`) and an **Auto-refresh: ON/OFF** toggle button.

#### 2. Search Header, Action Buttons, and Sub-Topic Pills

At the top of the main workspace, the active search panel summarizes:
- **Search Metadata**: Search ID (`SEARCH #19`), current status (`PENDING`, `EXPANDING`, `PROCESSING`, `COMPLETED`), creation timestamp, crawl depth (`Depth: Unlimited (All pages)` or `N / query`), and active per-provider option summaries (such as `LEBONCOIN: sort:relevance | shipping` and `VINTED: sort:relevance`).
- **Live Cron Schedule Bar**:
  - `CRON STATUS`: `Active (Every 30m)` or `Disabled`.
  - `LAST RUN`: UTC timestamp of the most recent crawl sweep.
  - `NEXT SCHEDULED RUN`: UTC timestamp of the next scheduled sweep.
  - `LIVE COUNTDOWN / RUNS`: Real-time 1-second countdown timer (`11m 44s`) and cumulative execution count (`Runs: 138`).
- **AI Sub-Topics Row**:
  - Displays `All (N)` alongside clickable pills for every AI-generated search query (for example `carte graphique 16Go`, `carte graphique RTX3080`, `carte graphique RTX3090`, `GPU 16Go`, etc.).
  - Clicking any sub-topic pill filters the listings below to show only ads discovered via that specific query. Clicking it again resets the sub-topic filter.
- **Evaluation Progress Bar**: Live counter and percentage bar tracking evaluated vs. stored listings for the current search.

#### 3. 5-Tier KPI Cards and 2x2 Chart.js Visual Analytics

Below the search header, six KPI cards display active (unsold) listing counts for each tier plus the average score against your target price:
- Clicking **MUST BUY (90-100)**, **EXTREMELY GOOD (70-89)**, **GOOD (50-69)**, **BAD (30-49)**, or **POOR (0-29)** immediately filters the results list to that tier.

Directly underneath, a 2x2 grid of live-updating **Chart.js** visualizations provides instant market intelligence:

1. **Cron Discoveries Timeline (Top-Left)**:
   - A stacked bar chart grouping newly evaluated listings into 30-minute time buckets color-coded by deal tier (`Must Buy`, `Ext. Good`, `Good`, `Bad / Poor`).
   - Time buckets marked with an asterisk (`*`) represent cron discovery waves that occurred after your last visit (`lastViewedAt`).
2. **Score Tier Distribution (Top-Right)**:
   - Bar chart comparing the volume of active unsold listings across the 5 score brackets.
3. **Provider Comparison (Bottom-Left)**:
   - Side-by-side comparison of total stored ads vs. recommended deals (`Score >= 50`) for each marketplace (`BACKMARKET`, `LEBONCOIN`, `VINTED`).
4. **Score vs. Price Plot (Bottom-Right)**:
   - An interactive scatter plot where each point represents a listing (`X-axis` = Price in EUR, `Y-axis` = AI Score out of 100).
   - Listings in the **top-left region** represent the highest AI scores at the lowest prices. Hover over any point to inspect its provider, title, price, and score.

#### 4. Collapsible Sub-Topic & Provider Telemetry Matrix

Click **Sub-Topic & Provider Telemetry Matrix** below the charts to expand two detailed performance tables:
- **Sub-Topic Yield**: Shows how many ads, `Must Buy` (`MB`), and `Extremely Good` (`EG`) deals each AI-generated search query produced, along with its average and maximum score. This lets you see which search keywords perform best on the marketplaces.
- **Provider Breakdown**: Shows stored count, evaluated count, `MB / EG / G` breakdown, average score, and average listing price per marketplace provider.

---

### 2.4 Browsing, Filtering, and Managing Deals

The animated preview below demonstrates browsing evaluated deals, switching pages, inspecting the 2x2 analytics charts, and opening an original marketplace listing directly from a deal card:

![Browsing and Filtering Deals in Listing Finder](docs/listing-finder_browsing_preview.gif)

#### 1. Using the Sticky Filter & Sort Toolbar

As you scroll down through results, the filter toolbar stays pinned to the top of the viewport:

- **Sort Modes**:
  - `Category`: Groups listings on the page into distinct sections by tier (`Must Buy`, `Extremely Good`, `Good`, `Bad`, `Poor`).
  - `Score`: Ranks all matching listings in a unified list from `100/100` down to `0/100` (tie-breaking by lowest price first).
  - `Price Asc`: Lowest price to highest price.
  - `Price Desc`: Highest price to lowest price.
  - `Newest`: Most recently evaluated listings first (ideal for reviewing the latest cron discoveries).
- **Tier Filter Pills**: `All`, `Must Buy`, `Ext. Good`, `Good`, `Bad`, `Poor`.
- **Provider Filter Pills**: `All`, `LEBONCOIN`, `VINTED`.
- **Keyword Search (`Search title or appraisal...`)**: Filters listings whose title, description, or AI reasoning text contains your search term.
- **Max Price (`Max EUR`)**: Filters out listings above a custom euro threshold.
- **Page Size Selector**: Choose `10 / page`, `20 / page`, `50 / page`, or `100 / page`.
- **Quick Toggle Checkboxes**:
  - `New Only`: Displays only listings evaluated after your last visit (`evaluatedAt > lastViewedAt`).
  - `Working`: Displays only listings confirmed functional by the AI (`isWorking = true`).
  - `Include Poor`: By default, when viewing `All` categories, `POOR (0-29)` listings (accessories, broken items, wrong categories) are hidden to keep your feed clean. Check `Include Poor` (or click the `Poor` tier pill) to inspect rejected items and read why the AI rejected them.
  - `Include Sold (N)`: By default, listings detected as sold, reserved, or deleted are hidden from active results and excluded from KPI counts. The label displays how many sold ads are currently hidden (for example `Include Sold (5)`). Check this box to include sold listings with a `SOLD` badge.
- **View Mode Toggle (`Cards` vs. `Table`)**:
  - `Cards`: Rich two-column deal cards with thumbnails, sub-score bars, and full appraisal boxes.
  - `Table`: High-density tabular view showing rank, score, tier, provider, sub-topic used, title, AI appraisal summary, price, sub-scores (`R / P / W / A`), and a direct `Open` link.

#### 2. Anatomy of a Deal Card

Every deal card in **Cards View** presents a complete, audit-ready breakdown of the listing:

1. **Card Header**:
   - Rank number (`#1`, `#2`, ...), marketplace badge (`LEBONCOIN`, `VINTED`, `BACKMARKET`), tier badge (`MUST BUY`, `EXTREMELY GOOD`, `GOOD`, `BAD`, `POOR`), `NEW` badge (if discovered since your last visit), `SOLD` badge (if sold/unavailable), functional status (`Functional` or `Defective / HS`), and overall score (`79 / 100`).
2. **Listing Metadata & Price Delta**:
   - Product thumbnail (loaded with `referrerpolicy="no-referrer"`).
   - Clickable listing title linking directly to the marketplace ad.
   - Seller-reported condition tag (e.g. `Tres bon etat`, `Bon etat`, `Etat neuf`), AI-estimated age (`Age: Moins de 1 an`, `Age: 2-3 ans (estime)`), and the exact AI sub-topic query that found the ad (`carte graphique 16Go`).
   - Listing price in EUR compared against your target budget with an automatic percentage delta (`-80% vs target` in emerald when below budget, `at target` in zinc, or `+7% vs target` in amber when above budget).
3. **4 Sub-Score Progress Bars**:
   - Individual `0–100` visual meters for **Relevance**, **Price**, **Condition**, and **Age**.
4. **AI Appraisal Box**:
   - Timestamped evidence summary explaining why the listing received its score, how its price compares to your target, its condition assessment, and which specifications were verified or missing. If Vision AI inspected the listing photo, the header displays `AI Appraisal (Vision)`.
5. **Card Footer**:
   - Marketplace external listing ID (`ID 3314803533`) and the **Open listing** button to open the ad on LeBonCoin, Vinted, or BackMarket in a new browser tab.

---

### 2.5 Managing Recurring Cron Monitoring and Availability Checks

#### 1. Tracking New Discoveries Since Your Last Visit

While the `worker` container is running, Symfony Scheduler dispatches `RunDueCronTasksMessage` every 60 seconds. Whenever a search's `CronTask` reaches its `nextRunAt` timestamp (every 30 minutes by default):
- The worker re-crawls all generated sub-topics across enabled providers.
- Already-stored ads are skipped automatically; only newly posted ads (`isComputed = false`) are queued for AI evaluation.
- When you return to the dashboard, the cyan **New Since Last Visit** banner appears at the top of the screen:
  - Click **Show New Only** to filter the view to unseen discoveries sorted by newest first.
  - Click **Mark All Seen** once you have reviewed the new deals; this updates `SearchRequest::$lastViewedAt` to the current UTC timestamp so the banner hides until the next cron wave finds new listings.

#### 2. Header Action Buttons

In the top-right corner of the active search panel, five action buttons let you control the search lifecycle:

- **Fetch New Ads**: Manually triggers an immediate incremental crawl across all sub-topics and providers without waiting for the cron countdown to reach zero.
- **Check Availability**:
  - Second-hand deals sell quickly. Clicking **Check Availability** dispatches a background `CheckSearchAvailabilityMessage` that verifies whether your top unsold recommended deals (`score >= 50`, up to 50 listings per run) are still available on the marketplace.
  - It visits each ad's detail page using polite per-provider pacing (`2.5s` LeBonCoin, `1.0s` Vinted).
  - Any ad that returns HTTP `404`/`410` or carries a sold/pending/reserved status is marked `isSold = true` and removed from your active deal counts.
  - If a marketplace returns an anti-bot HTTP `403` or `429`, the availability checker aborts immediately to protect your IP.
- **Re-evaluate All (Strict AI)**: Deletes existing `ListingEvaluation` rows for the current search, resets `isComputed = false` on all stored listings, and re-dispatches `EvaluateListingMessage` jobs for every ad. Use this after switching to a stronger LLM model in `.env.local`.
- **Pause Cron / Enable Cron**: Toggles the recurring 30-minute background schedule on or off for the current search.
- **Delete**: Deletes the search request along with all stored listings, evaluations, and its cron task.

#### 3. Provider Health and Anti-Bot Circuit Breaker

Marketplaces such as LeBonCoin (protected by DataDome) and Vinted enforce rate limits. Listing Finder includes a central `ProviderHealthService` backed by Symfony Cache:
- **Polite Outbound Pacing**: Enforces at least `2500ms` between LeBonCoin HTTP requests, `1000ms` between Vinted HTTP requests, and `2000ms` between SearXNG lookups.
- **15-Minute Cooldown Circuit Breaker**: If a provider responds with HTTP `403` or `429` (or an anti-bot challenge page), `ProviderHealthService` immediately places that provider into a 15-minute cooldown, stops all further requests to that marketplace during the cooldown window, and displays an amber warning banner at the top of the dashboard showing the exact UTC retry timestamp.

---

### 2.6 Command-Line Interface (CLI) Reference

Every action available in the web dashboard can also be automated or inspected from the terminal using Symfony Console commands.

> **Tip**: When running with Docker Compose, prefix commands with `docker compose exec php` (for example `docker compose exec php php bin/console app:search:results`).

#### 1. Start a New Search (`app:search:start`)

Expands a product specification with AI, creates the `SearchRequest` and `CronTask`, and dispatches provider crawl jobs to the Messenger queue.

```bash
# Basic search with target price of 50 EUR (AI chooses sub-topics count, 25 ads/query default)
docker compose exec php php bin/console app:search:start \
  --price=50 \
  "Canon printer AX380 in working condition with power cable"

# Unlimited multi-page crawl across LeBonCoin and Vinted with custom per-provider options:
# Nationwide shipping + local pickup within 30km of Lyon, excluding sold ads, minimum price 80 EUR
docker compose exec php php bin/console app:search:start \
  --price=300 \
  --unlimited \
  --interval=30 \
  --providers=leboncoin,vinted \
  -o leboncoin.sort=relevance \
  -o leboncoin.allow_shipping=true \
  -o leboncoin.allow_near_me=true \
  -o leboncoin.location=Lyon \
  -o leboncoin.radius_km=30 \
  -o leboncoin.exclude_sold=true \
  -o leboncoin.min_price=80 \
  -o vinted.sort=relevance \
  -o vinted.min_condition=very_good_plus \
  -o vinted.exclude_sold=true \
  -o vinted.min_price=80 \
  "Je voudrais une carte graphique avec minimum 16Go de VRAM"
```

All supported `app:search:start` options:
- `topic` *(required argument)*: Product specification, model name, or multi-sentence requirements.
- `--price=<EUR>` / `-p`: Target price in EUR (default: `100`).
- `--sub-topics=<1..15>` / `-s`: Number of AI search queries to generate (omit for `Auto`).
- `--limit=<N>` / `-l`: Max listings fetched per sub-topic per provider (default: `25`; `0` = Unlimited).
- `--unlimited` / `-u`: Crawl all available pages per query (up to `LISTING_PROVIDER_MAX_UNLIMITED_PAGES`).
- `--interval=<minutes>` / `-i`: Recurring cron interval in minutes (default: `30`).
- `--providers=<list>`: Comma-separated provider names (`leboncoin,vinted,backmarket`).
- `--provider-option=<provider.key=value>` / `-o`: Repeatable per-provider option flag:
  - LeBonCoin keys: `leboncoin.sort` (`relevance|newest|price_asc`), `leboncoin.allow_shipping` (`true|false`), `leboncoin.allow_near_me` (`true|false`), `leboncoin.location` (`<city or postcode>`), `leboncoin.radius_km` (`5..200`), `leboncoin.exclude_sold` (`true|false`), `leboncoin.search_in_title` (`true|false`), `leboncoin.owner_type` (`all|private|pro`), `leboncoin.min_price` (`<EUR>`).
  - Vinted keys: `vinted.sort` (`relevance|newest_first|price_low_to_high`), `vinted.min_condition` (`all|good_plus|very_good_plus|new_only`), `vinted.exclude_sold` (`true|false`), `vinted.min_price` (`<EUR>`).
- `--requirements=<text>` / `-r`: Additional requirements text merged into the specification.
- `--with-images`: Enable Vision AI photo inspection (when supported by the configured vision model).

#### 2. Inspect Evaluated Results (`app:search:results`)

Displays evaluated listings in formatted terminal tables with full AI appraisal blocks, or exports structured JSON.

```bash
# Display recommended deals (score >= 50) for the most recent search, grouped by category
docker compose exec php php bin/console app:search:results

# Display results for Search #19 sorted from highest score (100) to lowest
docker compose exec php php bin/console app:search:results 19 --by-score

# Include BAD (30-49) and POOR (0-29) tiers in the output
docker compose exec php php bin/console app:search:results 19 --by-category --all

# Filter results to a single tier (must_buy | extremely_good | good | bad | poor)
docker compose exec php php bin/console app:search:results 19 must_buy
docker compose exec php php bin/console app:search:results 19 extremely_good

# Search by topic substring instead of numeric ID and export as pretty-printed JSON
docker compose exec php php bin/console app:search:results "carte graphique" --by-score --json
```

#### 3. Trigger Recurring Cron Tasks Manually (`app:cron:run`)

Executes due cron tasks immediately and prints a summary table of all registered schedules:

```bash
# Run only cron tasks whose nextRunAt timestamp is currently due
docker compose exec php php bin/console app:cron:run

# Force an immediate incremental sweep across all active cron tasks regardless of schedule
docker compose exec php php bin/console app:cron:run --force
```

#### 4. List, Stop, or Purge Searches (`app:search:stop`)

Lists active recurring cron tasks, disables a search's cron schedule while keeping its stored listings, or purges a search completely:

```bash
# List all active cron tasks with their interval, run count, last run, and next run
docker compose exec php php bin/console app:search:stop --list

# Stop and remove the recurring cron schedule for Search #19 (keeps stored ads and evaluations)
docker compose exec php php bin/console app:search:stop 19

# Permanently delete Search #19 and purge all its stored ads and evaluations
docker compose exec php php bin/console app:search:stop 19 --purge
```

#### 5. Benchmark the Agentic AI Pipeline & Local Hardware (`app:ai:benchmark`)

Verifies your active LLM/VLM connection and runs an instant end-to-end benchmark of Stage 0 (`TopicExpansionAgent`), Stage 1 (`checkCoreProductGatekeeper` on an unrequested accessory trap), and Stage 2 (`ListingEvaluatorAgent::evaluate` with post-LLM calibration guardrails), printing per-stage latencies and a 1,000-listing throughput projection across Local GPU VRAM tiers:

```bash
# Run the interactive CLI benchmark with default GPU specification (350 EUR target)
docker compose exec php php bin/console app:ai:benchmark

# Benchmark a custom product specification and target budget
docker compose exec php php bin/console app:ai:benchmark \
  --spec="Switch reseau manageable avec 8 ports Gigabit RJ45 et 2 cages SFP+ 10GbE" \
  --price=110

# Export structured benchmark telemetry as JSON
docker compose exec php php bin/console app:ai:benchmark --json
```

#### 6. Run the Background Messenger Worker (`messenger:consume`)

In Docker Compose and Kubernetes, the `worker` container runs this command automatically. If you are running PHP directly on the host during debugging, start the worker with:

```bash
php bin/console messenger:consume scheduler_default async --time-limit=3600 --memory-limit=256M -vv
```
