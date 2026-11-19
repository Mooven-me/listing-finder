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
- `SearchRequest`: Stores the user's product specification (`topic` and optional overflow/details in `userRequirements`), `targetPrice`, `enableImages`, AI-generated `generatedSubTopics` (JSON list of search queries/titles), `enabledProviders` (JSON list), `providerOptions` (JSON map `[providerName => [optionKey => normalizedValue]]`), `status` (`SearchStatus`: `pending`, `expanding`, `processing`, `completed`, `failed`), and a 1-to-1 relation to `CronTask`.
- `Listing`: A marketplace ad linked to a `SearchRequest`. Deduplicated by unique constraint `(search_request_id, provider, external_id)`. Tracks `searchTopicUsed`, `title`, `description`, `price`, `currency`, `url`, `imageUrls`, `conditionState`, `publishedAt`, and `isComputed` (whether AI evaluation has completed).
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
  - Communicates with **Ollama** (`/api/chat` with strict JSON Schema `format`, `temperature: 0.2`, and runtime `options.num_ctx` controlled by `AI_NUM_CTX=8192` — no custom Ollama Modelfile required) or **OpenAI-compatible** (`/v1/chat/completions`) endpoints, deserializing responses into validated `readonly` DTOs (`TopicExpansionResultDto`, `ListingEvaluationResultDto`).
  - Places `reasoning` **first** in the Ollama JSON schema for `ListingEvaluationResultDto` so compact local models (e.g. `lfm2.5:8b`) analyze listing evidence step-by-step before emitting booleans and numeric scores.
  - Exposes `supportsVision(): bool` and `getVisionModelName(): string` (probes Ollama `/api/show` `capabilities` for `"vision"`, cached for 10 minutes in Symfony Cache + in-memory memo). Tracks `imageAnalyzed` truthfully (never marks `imageAnalyzed = true` if vision is unsupported or rejected).
- `TopicExpansionAgent`: Takes the user's unified Product Specification and an optional `requestedCount` (`1..15`, or `null` for `Auto` where the AI picks `5..8` queries). Enforces short 2–3 word marketplace search queries (strict-AND safe), sanitizes verbose LLM output (`<= 4` words, strips forbidden meta/marketplace words and edge stopwords), preserves exact alphanumeric model codes from the buyer text, and guarantees 2-word high-recall anchor queries (domain-agnostic head-noun + distinctive token pairing).
- `SearxngProductSpecService`: Optional, 100% domain-agnostic product datasheet/spec lookup backed by a self-hosted **SearXNG** JSON API (`SEARXNG_BASE_URL`, `SEARXNG_TIMEOUT`). When a listing title contains a concrete alphanumeric model code (excluding generic units/ports like `1Gbps`, `15m`, `Cat6`, `RJ45`), queries SearXNG and caches concise factual snippets for 7 days in Symfony Cache for injection into `ListingEvaluatorAgent`.
- `ListingEvaluatorAgent`: Evidence-only, anti-hallucination appraisal of a `Listing` against the `SearchRequest` specification using only the listing title, enriched seller description, condition, and optional `Verified External Product Reference (SearXNG)`. Skips downloading images completely when `!$llmClient->supportsVision()`. Uses structured evidence fields (`is_wrong_product_or_accessory`, `matched_specs`, `missing_or_unverified_specs`) followed by universal post-LLM guardrails (`enforceStrictCalibration`) enforcing wrong-product caps (`<= 10`), working-condition caps (`<= 15`), missing/unverified spec caps (`<= 45` for 1, `<= 29` for 2+), exact symbol/model-code token verification against `title + description + condition + SearXNG`, price-to-target ratios, and weighted composite ceilings.

### 5. Marketplace Providers & DRY Per-Provider Options (`src/Service/Provider/`, `src/Dto/Provider/`)
- `ListingProviderInterface` & `AbstractHttpListingProvider` (tagged `app.listing_provider` and resolved by `ListingProviderRegistry`):
  - Every provider declares its UI/CLI options via `getOptionDefinitions(): list<ProviderOptionDefinition>` (`checkbox`, `select`, `text`, `number`, with optional `dependsOn` conditional visibility). `ListingProviderRegistry::getOptionSchemas()` drives dynamic Twig option cards automatically, and `normalizeProviderOptions()` validates/normalizes form & CLI inputs (including unchecked checkboxes).
  - `search(string $query, ?float $maxPrice = null, ?int $limit = 25, array $options = [])` supports `?int $limit = null` (Unlimited multi-page crawling capped by `LISTING_PROVIDER_MAX_UNLIMITED_PAGES=15`).
  - `fetchListingDetails(string $url): ?ListingDetailsDto` provides optional per-listing detail-page scraping when search-results pages omit the seller's real description.
- `FrenchGeocoderService`: Resolves French cities/postal codes via `https://api-adresse.data.gouv.fr/search/` with Symfony Cache (`CacheInterface`, 7-day TTL) + in-memory cache.
- `LeBonCoinProvider`: Crawls LeBonCoin's SSR Next.js `<script id="__NEXT_DATA__">` payload (`https://www.leboncoin.fr/recherche`) across pages (35 ads/page, respects `max_pages`, never sends `Referer` to avoid DataDome 403). Tracks `$ssrSucceeded` so valid 200 SSR responses with 0 ads return `[]` cleanly without hitting the blocked `api.leboncoin.fr` 403 fallback. Defaults to native **relevance** ranking (omits `sort=time`) and nationwide shipping (`shipping_type=mondial_relay,colissimo,chronopost,shop2shop,relais_colis`). When both `allow_shipping` and `allow_near_me` (+ `location`/`radius_km`) are enabled, runs a deduplicated **UNION** of nationwide shipping + local radius (`locations=<City_Postcode>__<lat>_<lng>_5000_<radiusMeters>`). Also supports `search_in_title` (`search_in=subject`), `owner_type` (`private`/`pro`), and `min_price`. Implements `fetchListingDetails()` to fetch the individual ad page (`props.pageProps.ad.body`, `category_name`, `brand`, and generic `attributes`) since LeBonCoin search-result pages return an empty `body: ""`.
- `VintedProvider`: Crawls Vinted's SSR catalog HTML (`https://www.vinted.fr/catalog`, 96 ads/page) across pages. Tracks `$ssrSucceeded` so valid 200 SSR responses with 0 items return `[]` without hitting the 404 API v2 fallback. Defaults to `order=relevance` and supports `sort`, `min_condition` (`status_ids[]`), and `min_price`. Implements `fetchListingDetails()` to extract the full seller description from individual item pages.
- `BackMarketProvider`: Parses JSON-LD product items from `https://www.backmarket.fr/fr-fr/search`.
- `MockListingProvider`: Deterministic fixture generator for tests (`LISTING_PROVIDER_FALLBACK_TO_MOCK=false` in production).

### 6. Async Messenger & Scheduler Flow (`src/Message/`, `src/MessageHandler/`, `src/Schedule.php`)
- `SearchDispatcherService` dispatches one `FetchSubTopicListingsMessage` per `(subTopic, provider)`.
- `FetchSubTopicListingsHandler` queries the provider, acquires a `LockFactory` lock per ad, upserts new `Listing` rows, dispatches `EvaluateListingMessage` only for uncomputed listings (`isComputed = false`), and logs fetched/queued counts.
- `EvaluateListingHandler` lazily enriches missing/short listing descriptions once via `AbstractHttpListingProvider::fetchListingDetails()` (persisting the enriched description on `Listing`), runs `ListingEvaluatorAgent::evaluate()`, persists `ListingEvaluation`, marks `Listing::isComputed = true`, and marks `SearchRequest` as `Completed` once all stored listings are evaluated.
- `Schedule.php` dispatches `RunDueCronTasksMessage` every 60 seconds on transport `scheduler_default`; `RunDueCronTasksHandler` checks `CronTaskRepository::findDueTasks()` and dispatches incremental searches.

### 7. Web Dashboard & CLI (`src/Controller/DashboardController.php`, `src/Command/`)
- **Web Dashboard**:
  - `GET /` (`app_dashboard`): Bound via `#[MapQueryString] DashboardFilterDto`. Renders 5-tier KPIs, live cron countdown, 3 Chart.js charts, telemetry matrix, filter/sort toolbar (`category` vs `score`, tier filter, provider filter, sub-topic filter, keyword search, max price, working only, cards vs table view), and server-side pagination. Always keep the UI clean, dark-neutral (`zinc`), and completely free of emojis.
  - The **New Search Request** modal automatically disables and greys out the **Vision AI** checkbox when `LlmClientInterface::supportsVision()` is `false` (and `POST /search/new` enforces `enableImages = false` server-side).
  - `GET /api/dashboard/status/{id}`: Real-time JSON telemetry endpoint polled every 5 seconds to update global stats (`queued_messages` excludes `queue_name = 'failed'`), tier counts, cron countdown, status transitions (`Processing` -> `Completed` once the async queue empties, including 0-ad searches), and Chart.js datasets live.
  - `POST /search/new`, `/search/{id}/refresh`, `/search/{id}/reevaluate`, `/search/{id}/cron-toggle`, `/search/{id}/delete`.
- **CLI Commands**:
  - `app:search:start`: Expands specification, creates `SearchRequest` + `CronTask`, dispatches jobs (supports `--sub-topics`/`-s`, `--limit`/`-l`, `--unlimited`/`-u`, and repeatable `--provider-option`/`-o provider.key=value`).
  - `app:search:results`: Displays results in CLI (`--by-category`, `--by-score`, positional `category` filter, `--all`, `--json`).
  - `app:cron:run` & `app:search:stop`.

### 8. Deployment Topologies
- **Development (`compose.yaml` + `compose.override.yaml`)**: Runs `php` (FrankenPHP dev), `worker`, `searxng` (`docker/searxng/settings.yml` with JSON API enabled), and local `database` (PostgreSQL 16).
- **Production Docker Compose (`compose.yaml` + `compose.prod.yaml`)**: Runs `php`, `worker`, and `searxng` using `.env.prod` and an external shared PostgreSQL database (no local database container).
- **Production Kubernetes (`k8s/`)**: Kustomize manifests for **k3s + Traefik** in namespace `listing-finder` (`deployment-web.yaml`, `deployment-worker.yaml`, `searxng.yaml`, `service.yaml`, `ingress.yaml`, `configmap.yaml`, `secret.example.yaml`). Connects to in-cluster PostgreSQL (`postgres-postgresql.postgres.svc.cluster.local:5432/listing_finder`), in-cluster SearXNG (`http://searxng:8080`), and host Ollama (`http://moovenserver:11434`). When reusing the `listing-finder:prod` image tag with `imagePullPolicy: IfNotPresent`, run `kubectl -n listing-finder rollout restart deployment/listing-finder-web deployment/listing-finder-worker` after importing into k3s containerd.

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
