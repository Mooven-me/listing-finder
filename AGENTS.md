# AGENTS.md

This is a Symfony project (**Listing Finder**). Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

> **Mandatory Maintenance Rule for AI Agents**: Whenever you add, modify, or remove code, services, entities, environment variables, providers, AI pipeline rules, UI behavior, or deployment manifests, **you MUST update this `AGENTS.md` file (and `README.md` for user-facing changes) yourself in the same task/commit** so future agents always have an accurate, up-to-date architectural context.

## Application Architecture & Domain Guide

**Listing Finder** is a domain-agnostic AI second-hand deal radar built on **Symfony 8.1**, **PHP 8.5**, **FrankenPHP**, **Doctrine ORM (PostgreSQL 16)**, **Symfony Messenger & Scheduler**, **Symfony Lock**, and **Twig + Chart.js**.

### 1. Purpose & Domain-Agnostic Rule
- Users search for **any product in the world** by entering a unified **Product Specification** (either a specific product/model name like `"canon printer ax380"` or a multi-sentence description of desired technical specs, features, and condition) along with a **Target Price (EUR)**.
- **NEVER hardcode product-specific or category-specific keywords/regexes** (such as specific electronics, networking switches, etc.) into the AI prompts or evaluation guardrails. All prompts and heuristics must remain 100% universal across all product categories.

### 2. Core Entities (`src/Entity/`)
- `SearchRequest`: Stores the user's product specification (`topic` and optional overflow/details in `userRequirements`), `targetPrice`, `enableImages`, AI-generated `generatedSubTopics` (JSON list of search queries/titles), `enabledProviders` (JSON list), `providerOptions` (JSON map `[providerName => [optionKey => normalizedValue]]`), `status` (`SearchStatus`: `pending`, `expanding`, `processing`, `completed`, `failed`), `lastViewedAt` (watermark for `"New Since Last Visit"` cron discovery tracking), and a 1-to-1 relation to `CronTask`.
- `Listing`: A marketplace ad linked to a `SearchRequest`. Deduplicated by unique constraint `(search_request_id, provider, external_id)`. Tracks `searchTopicUsed`, `title`, `description`, `price`, `currency`, `url`, `imageUrls`, `conditionState`, `publishedAt`, `isSold` (whether the ad is sold/reserved/closed/removed — hidden by default in dashboard unless `showSold=1`), and `isComputed` (whether AI evaluation has completed).
- `ListingEvaluation`: AI appraisal for a `(SearchRequest, Listing)` pair. Stores `scoreOutOf100` (`0–100`), `category` (`DealCategory`), 4 sub-scores (`priceScore`, `workingConditionScore`, `productAgeScore`, `relevanceScore`), `isWorking`, `estimatedAge`, `aiReasoning`, and `imageAnalyzed`.
- `CronTask`: Recurring schedule metadata for a `SearchRequest` (`intervalMinutes`, nullable `limitPerSubTopic` where `null` = Unlimited multi-page crawl, `lastRunAt`, `nextRunAt`, `runCount`).

### 3. 5-Tier Deal Classification (`App\Enum\DealCategory`)
- `MustBuy` (`must_buy`): **90–100** — Rare, exact product & specification match, functional, and priced at or below target budget.
- `ExtremelyGood` (`extremely_good`): **70–89** — Matches all key specifications, functional, fair price around or below target.
- `Good` (`good`): **50–69** — Matches main product and most specs, acceptable price or minor wear.
- `Bad` (`bad`): **30–49** — Overpriced relative to target, missing secondary features, or worn condition.
- `Poor` (`poor`): **0–29** — Wrong product type / accessory only (`0–10`), defective/HS (`0–15`), or missing mandatory specifications from the user's request (`10–29`).

### 4. AI Pipeline (`src/Service/Ai/`)
- `ConfigurableLlmClient` (`LlmClientInterface`):
  - Communicates with **Ollama** (`/api/chat` with strict JSON Schema `format`, `temperature: 0.2`, and runtime `options.num_ctx` controlled by `AI_NUM_CTX=8192` — no custom Ollama Modelfile required) or **OpenAI-compatible** (`/v1/chat/completions`, e.g., Groq free tier with `openai/gpt-oss-120b` using `AI_BASE_URL=https://api.groq.com/openai/v1` and `AI_API_KEY` stored privately in k8s Secret) endpoints, deserializing responses into validated `readonly` DTOs (`TopicExpansionResultDto`, `ProductTypeGatekeeperResultDto`, `ListingEvaluationResultDto`).
  - Supports cross-request pacing (`AI_MIN_REQUEST_INTERVAL_MS`, e.g. `9000` for Groq's `8,000 TPM` free tier; capped to `2000ms` in non-CLI web SAPI) and bounded HTTP `429` `Retry-After` backoff on OpenAI-compatible endpoints (caps per-request timeout and total wall-clock retry budget to `12s` in web SAPI and fails fast immediately if `Retry-After` exceeds the retry sleep window so `TopicExpansionAgent` falls back cleanly without hitting PHP's 30s `max_execution_time`).
  - Places `reasoning` **first** in the JSON schema for `ListingEvaluationResultDto` so models analyze listing evidence step-by-step before emitting booleans and numeric scores.
  - Exposes `supportsVision(): bool` and `getVisionModelName(): string` controlled by `AI_VISION_ENABLED` (`auto|true|false`; in `auto` mode probes Ollama `/api/show` `capabilities` for `"vision"`, cached for 10 minutes in Symfony Cache + in-memory memo; defaults to `false` for OpenAI text models unless explicitly `true`). Tracks `imageAnalyzed` truthfully (never marks `imageAnalyzed = true` if vision is unsupported or rejected).
- `TopicExpansionAgent`: Takes the user's unified Product Specification and an optional `requestedCount` (`1..15`, or `null` for `Auto` where the AI picks `5..8` queries). Extracts `core_product_noun` + `core_product_synonyms` and enforces that every generated marketplace query includes the core product noun (preventing bare spec-token queries like `"SFP+ 2"` that flood results with accessories/modules). Enforces short 2–3 word marketplace search queries (strict-AND safe), sanitizes verbose LLM output (`<= 4` words, strips forbidden meta/marketplace words and edge stopwords), preserves exact alphanumeric model codes from the buyer text, and guarantees 2-word high-recall anchor queries (domain-agnostic head-noun + distinctive token pairing, excluding tokens with `+`/`%`/digits as head nouns).
- `SearxngProductSpecService`: Optional, 100% domain-agnostic product datasheet/spec lookup backed by a self-hosted **SearXNG** JSON API (`SEARXNG_BASE_URL`, `SEARXNG_TIMEOUT`). Uses `bing` and `duckduckgo` (`brave`, `google`, `google cse`, `startpage` disabled in `docker/searxng/settings.yml` and `k8s/searxng.yaml` to eliminate upstream HTTP 429 bans). Builds compact 4-token canonical queries, applies 2-second polite outbound throttling via `ProviderHealthService`, caches positive results for 7 days and empty results (`__SEARXNG_NO_RESULTS__`) for 24 hours in Symfony Cache, and is skipped completely when a listing is rejected in Stage 1.
- `ListingEvaluatorAgent`: Evidence-only, anti-hallucination two-stage appraisal of a `Listing` against the `SearchRequest` specification:
  - **Stage 1 (`checkCoreProductGatekeeper()`)**: Runs BEFORE detail-page scraping and SearXNG lookup! First runs a zero-LLM universal multilingual accessory/component title check (e.g., `module`, `transceiver`, `cable`, `adapter`, `charger`, `case`, `bracket`, etc. unless the buyer asked for that item), then a compact LLM `ProductTypeGatekeeperResultDto` check (`buyer_wants_item_type` vs. `listing_sells_item_type`). Checks only `listingSellsItemType` (never `briefReason`, to avoid negated-phrase false rejections). Rejects mismatched categories/accessories immediately at `<= 10/100 (POOR)` saving provider requests, SearXNG calls, and Stage-2 tokens.
  - **Stage 2 (`evaluate()`)**: Appraises using only the listing title, enriched seller description, condition, and optional `Verified External Product Reference (SearXNG)`. Skips downloading images completely when `!$llmClient->supportsVision()`. Handles conditional/alternative buyer branches (e.g., `"if X then Y, otherwise Z"` — skips penalizing conditional exact tokens like `sfp+` when the buyer explicitly wrote an alternative branch). Applies universal post-LLM guardrails (`enforceStrictCalibration`) enforcing wrong-product/accessory caps (`<= 10`), working-condition caps (`<= 15`), missing/unverified spec caps (`<= 45` for 1, `<= 29` for 2+), exact symbol/model-code token verification against `title + description + condition + SearXNG`, price-to-target ratios, and weighted composite ceilings.

### 5. Marketplace Providers, Anti-Rate-Limit Pacing & DRY Per-Provider Options (`src/Service/Provider/`, `src/Dto/Provider/`)
- `ProviderHealthService`: Central polite pacing (`throttle(provider, minIntervalMs)`) and 15-minute cooldown circuit breaker (`isCoolingDown()`, `markBlocked()`, `getActiveBlocks()`) backed by Symfony Cache so providers immediately stop hammering a marketplace upon HTTP `403` (e.g. DataDome anti-bot challenge) or `429` and surface a warning banner in the dashboard.
- `ListingProviderInterface` & `AbstractHttpListingProvider` (tagged `app.listing_provider` and resolved by `ListingProviderRegistry`):
  - Every provider declares its UI/CLI options via `getOptionDefinitions(): list<ProviderOptionDefinition>` (`checkbox`, `select`, `text`, `number`, with optional `dependsOn` conditional visibility). `ListingProviderRegistry::getOptionSchemas()` drives dynamic Twig option cards automatically, and `normalizeProviderOptions()` validates/normalizes form & CLI inputs (including unchecked checkboxes).
  - `search(string $query, ?float $maxPrice = null, ?int $limit = 25, array $options = [])` supports `?int $limit = null` (Unlimited multi-page crawling capped by `LISTING_PROVIDER_MAX_UNLIMITED_PAGES`, set to `5` in production k8s).
  - `fetchListingDetails(string $url): ?ListingDetailsDto` provides optional per-listing detail-page scraping (`description`, `conditionState`, `isSoldOrUnavailable`, `rateLimited`).
- `FrenchGeocoderService`: Resolves French cities/postal codes via `https://api-adresse.data.gouv.fr/search/` with Symfony Cache (`CacheInterface`, 7-day TTL) + in-memory cache.
- `LeBonCoinProvider`: Crawls LeBonCoin's SSR Next.js `<script id="__NEXT_DATA__">` payload (`https://www.leboncoin.fr/recherche`) across pages (35 ads/page, respects `max_pages`, never sends `Referer`, 2.5s polite pacing via `ProviderHealthService`). On HTTP `403`/`429`, records a 15-minute cooldown in `ProviderHealthService` and returns `[]` cleanly without hitting `api.leboncoin.fr`. Pre-checked `exclude_sold` option filters out `status !== 'active'` or `options.has_option / transaction_status` in `pending`/`sold`/`reserved` states during crawl. Defaults to native **relevance** ranking (omits `sort=time`) and nationwide shipping (`shipping_type=mondial_relay,colissimo,chronopost,shop2shop,relais_colis`). When both `allow_shipping` and `allow_near_me` (+ `location`/`radius_km`) are enabled, runs a deduplicated **UNION** of nationwide shipping + local radius (`locations=<City_Postcode>__<lat>_<lng>_5000_<radiusMeters>`). Also supports `search_in_title` (`search_in=subject`), `owner_type` (`private`/`pro`), and `min_price`. Implements `fetchListingDetails()` to extract `props.pageProps.ad.body`, `category_name`, `brand`, generic `attributes`, and sold/pending status (`404`/`410`, `ad.status`, `transaction_status`).
- `VintedProvider`: Crawls Vinted's SSR catalog HTML (`https://www.vinted.fr/catalog`, 96 ads/page, 1.0s polite pacing, 15-min cooldown on `403`/`429`). Pre-checked `exclude_sold` option skips closed/reserved/unbuyable items. Defaults to `order=relevance` and supports `sort`, `min_condition` (`status_ids[]`), and `min_price`. Implements `fetchListingDetails()` to extract full seller description and detect sold/reserved/closed state (`404`/`410`, escaped RSC `\"is_closed\":true`, `\"is_reserved\":true`, `\"can_buy\":false`).
- `BackMarketProvider`: Parses JSON-LD product items from `https://www.backmarket.fr/fr-fr/search`.
- `MockListingProvider`: Deterministic fixture generator for tests (`LISTING_PROVIDER_FALLBACK_TO_MOCK=false` in production).

### 6. Async Messenger & Scheduler Flow (`src/Message/`, `src/MessageHandler/`, `src/Schedule.php`)
- `SearchDispatcherService` dispatches one `FetchSubTopicListingsMessage` per `(subTopic, provider)`.
- `FetchSubTopicListingsHandler` queries the provider, acquires a `LockFactory` lock per ad, upserts new `Listing` rows, dispatches `EvaluateListingMessage` only for uncomputed listings (`isComputed = false`), and logs fetched/queued counts.
- `EvaluateListingHandler` skips already-sold listings (`isSold = true`), runs **Stage-1 `checkCoreProductGatekeeper()` BEFORE fetching detail pages** (saving marketplace requests and SearXNG calls on accessories/wrong products), lazily enriches surviving listings' descriptions once via `AbstractHttpListingProvider::fetchListingDetails()` (if the detail page reports `isSoldOrUnavailable`, marks `Listing::isSold = true` & skips Stage 2), runs Stage-2 `ListingEvaluatorAgent::evaluate(..., runGatekeeper: false)`, persists `ListingEvaluation`, marks `Listing::isComputed = true`, and marks `SearchRequest` as `Completed` once all stored listings are evaluated.
- `CheckSearchAvailabilityHandler` (`CheckSearchAvailabilityMessage`): Safely re-checks availability ONLY for top unsold recommended deals (`score >= 50`, capped at 50 per run) under a `LockFactory` lock with per-provider pacing and an immediate circuit-breaker abort on `rateLimited` (`403`/`429`), marking newly sold/deleted ads `Listing::isSold = true`.
- `Schedule.php` dispatches `RunDueCronTasksMessage` every 60 seconds on transport `scheduler_default`; `RunDueCronTasksHandler` checks `CronTaskRepository::findDueTasks()` and dispatches incremental searches.

### 7. Web Dashboard & CLI (`src/Controller/DashboardController.php`, `src/Command/`)
- **Web Dashboard**:
  - `GET /` (`app_dashboard`): Bound via `#[MapQueryString] DashboardFilterDto`. Renders active provider cooldown warnings (`provider_health`), a **"New Since Last Visit"** cron discovery banner (`unseen_total`, `Show New Only`, `Mark All Seen`), 5-tier KPIs (excluding sold ads from active counts + `sold_count` pill), live cron countdown, **4 Chart.js charts** in a 2x2 grid (*Score Tier Distribution*, *Provider Comparison*, *Score vs. Price Scatter Plot*, *Cron Discoveries Timeline* stacked bar chart in 30-min buckets), telemetry matrix, filter/sort toolbar (`category`, `score`, `price_asc`, `price_desc`, `newest`, tier filter, provider filter, sub-topic filter, keyword search, max price, `Working Only`, `New Only`, `Include Sold`, cards vs table view), `NEW`/`SOLD` badges, and server-side pagination. Always keep the UI clean, dark-neutral (`zinc`), and completely free of emojis.
  - The **New Search Request** modal automatically disables and greys out the **Vision AI** checkbox when `LlmClientInterface::supportsVision()` is `false` (and `POST /search/new` enforces `enableImages = false` server-side).
  - `GET /api/dashboard/status/{id}`: Real-time JSON telemetry endpoint polled every 5 seconds to update global stats (`queued_messages` excludes `queue_name = 'failed'`), tier counts, `sold_count`, `unseen` metrics, `discovery_timeline`, `provider_health`, cron countdown, status transitions (`Processing` -> `Completed` once the async queue empties, including 0-ad searches), and all 4 Chart.js datasets live.
  - `POST /search/new` (expands sub-topics before persisting and flushes `SearchRequest` + `CronTask` atomically so failed requests never leave empty `expanding` rows), `/search/{id}/refresh`, `/search/{id}/reevaluate`, `/search/{id}/check-availability`, `/search/{id}/mark-seen`, `/search/{id}/cron-toggle`, `/search/{id}/delete`.
- **CLI Commands**:
  - `app:search:start`: Expands specification, creates `SearchRequest` + `CronTask`, dispatches jobs (supports `--sub-topics`/`-s`, `--limit`/`-l`, `--unlimited`/`-u`, and repeatable `--provider-option`/`-o provider.key=value`).
  - `app:search:results`: Displays results in CLI (`--by-category`, `--by-score`, positional `category` filter, `--all`, `--json`).
  - `app:cron:run` & `app:search:stop`.
  - `app:ai:benchmark` (`AiBenchmarkCommand`): End-to-end CLI benchmark and verification of the 3-stage Agentic AI pipeline (`TopicExpansionAgent`, `Stage-1 Gatekeeper`, `Stage-2 Reasoning-First Evaluator + Guardrails`) with per-stage latency telemetry, 1,000-listing throughput projections across Local GPU VRAM tiers vs. rate-limited Cloud APIs, and `--json` export.

### 8. Deployment Topologies & Documentation Structure
- **Development (`compose.yaml` + `compose.override.yaml`)**: Runs `php` (FrankenPHP dev), `worker`, `searxng` (`docker/searxng/settings.yml` with JSON API enabled, `bing`+`duckduckgo`), and local `database` (PostgreSQL 16).
- **Production Docker Compose (`compose.yaml` + `compose.prod.yaml`)**: Runs `php`, `worker`, and `searxng` using `.env.prod` and an external shared PostgreSQL database (no local database container).
- **Production Kubernetes (`k8s/`)**: Kustomize manifests for **k3s + Traefik** in namespace `listing-finder` (`deployment-web.yaml`, `deployment-worker.yaml` with **1 replica** to prevent parallel IP bursts triggering marketplace 403s, `searxng.yaml`, `service.yaml`, `ingress.yaml`, `configmap.yaml`, `secret.example.yaml`). Connects to in-cluster PostgreSQL (`postgres-postgresql.postgres.svc.cluster.local:5432/listing_finder`), in-cluster SearXNG (`http://searxng:8080`), and Groq OpenAI-compatible API (`https://api.groq.com/openai/v1`, `openai/gpt-oss-120b`, `AI_MIN_REQUEST_INTERVAL_MS=9000`, private `AI_API_KEY` in `listing-finder-secret`). When reusing the `listing-finder:prod` image tag with `imagePullPolicy: IfNotPresent`, run `kubectl -n listing-finder rollout restart deployment/listing-finder-web deployment/listing-finder-worker` after importing into k3s containerd.
- **Documentation (`README.md` & `docs/`)**:
  - `README.md`: Concise, human-readable overview introducing what the application does using `docs/listing-finder_screen.png`, `docs/listing-finder_creation_screen.png`, and `docs/listing-finder_browsing_preview.gif`, followed by a quick installation setup and links to `docs/`.
  - `docs/installation.md`: Detailed installation guide (Ollama vs. OpenAI-compatible setup, environment variables reference, Docker Compose prod, and k3s Kubernetes deployment).
  - `docs/usage.md`: Complete user guide (writing specifications, per-provider options for LeBonCoin and Vinted, deal scoring tiers, dashboard analytics, cron/availability management, and CLI commands).
  - `docs/architecture.md`: Technical deep-dive into the multi-stage AI pipeline, SearXNG datasheet verification, `ProviderHealthService` anti-bot pacing, and local GPU VRAM sizing.

---

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).
