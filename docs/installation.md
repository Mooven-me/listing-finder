# Installation & Deployment Guide

This guide covers setting up **Listing Finder** for local usage with Docker Compose, configuring local (**Ollama**) or cloud (**OpenAI-compatible**) AI providers, and deploying in production with Docker Compose or Kubernetes (`k3s` + Traefik).

---

## 1. Prerequisites

- **Docker Engine** and **Docker Compose v2** (`docker compose version`)
- **Git**
- **An LLM Backend** (choose one):
  - **Local AI (Ollama)**: Installed on your host machine (`http://localhost:11434`) or accessible on your local network.
  - **OpenAI-Compatible Cloud API**: An API endpoint and key from **Groq** (`https://api.groq.com/openai/v1`), OpenAI, Together AI, or a self-hosted vLLM/LiteLLM server.

---

## 2. Step-by-Step Local Installation

### Step 1: Clone the Repository

```bash
git clone <your-repository-url> listing-finder
cd listing-finder
```

### Step 2: Create `.env.local` and Configure Your AI Backend

Default configuration values live in [`.env`](../.env). Create an untracked `.env.local` file at the project root so your secrets and local settings are not committed:

```bash
cp .env .env.local
```

Configure **Option A (Local Ollama)** or **Option B (OpenAI-Compatible Cloud API)** in `.env.local`:

#### Option A: Local AI with Ollama (Recommended for 24/7 Unthrottled Hunting)

1. Pull an instruction-tuned model on your host machine (e.g. `lfm2.5:8b`, `qwen2.5:7b`, `gemma3:12b`, or `llama3.1:8b`):

   ```bash
   ollama pull lfm2.5:8b
   ```

2. Configure `.env.local`:

   ```dotenv
   AI_PROVIDER=ollama
   AI_BASE_URL=http://host.docker.internal:11434
   AI_MODEL=lfm2.5:8b
   AI_VISION_MODEL=lfm2.5:8b
   AI_VISION_ENABLED=auto
   AI_NUM_CTX=8192
   AI_MIN_REQUEST_INTERVAL_MS=0
   ```

   > **Linux Host Note**: `compose.yaml` and `compose.override.yaml` automatically map `host.docker.internal` to the Docker host gateway. Make sure your host Ollama daemon listens on `0.0.0.0:11434` (set `OLLAMA_HOST=0.0.0.0` in the Ollama systemd service if needed).

#### Option B: Cloud OpenAI-Compatible API (e.g. Groq Free Tier)

Listing Finder works with any OpenAI-compatible `/v1/chat/completions` endpoint and includes built-in request pacing (`AI_MIN_REQUEST_INTERVAL_MS`) plus automatic HTTP `429` `Retry-After` backoff:

```dotenv
AI_PROVIDER=openai
AI_BASE_URL=https://api.groq.com/openai/v1
AI_API_KEY=gsk_your_private_api_key_here
AI_MODEL=openai/gpt-oss-120b
AI_VISION_MODEL=openai/gpt-oss-120b
AI_VISION_ENABLED=false
AI_MIN_REQUEST_INTERVAL_MS=9000
```

> **Disabling Mock Fallback**: In development, `LISTING_PROVIDER_FALLBACK_TO_MOCK=true` allows offline testing with synthetic fixtures. For real deal hunting, set `LISTING_PROVIDER_FALLBACK_TO_MOCK=false` in `.env.local`.

### Step 3: Build and Start the Containers

```bash
docker compose up -d --build
```

This launches four containers:

| Service | Role |
| :--- | :--- |
| **`php`** | FrankenPHP web server (`http://localhost` / `https://localhost`). Automatically installs Composer dependencies and runs Doctrine migrations on startup. |
| **`worker`** | Symfony Messenger & Scheduler consumer (`messenger:consume scheduler_default async`). Crawls marketplaces, runs AI evaluations, checks availability, and triggers due cron tasks every 60 seconds. |
| **`database`** | PostgreSQL 16 Alpine database persisted in the `database_data` Docker volume. |
| **`searxng`** | Self-hosted SearXNG instance (`http://searxng:8080`) configured via [`docker/searxng/settings.yml`](../docker/searxng/settings.yml) (`bing` + `duckduckgo` JSON API) for external product datasheet lookups. |

### Step 4: Verify the Installation

1. Check container status:

   ```bash
   docker compose ps
   ```

2. Inspect the background worker logs:

   ```bash
   docker compose logs -f worker
   ```

3. Run the built-in AI pipeline benchmark to verify connectivity with your LLM backend:

   ```bash
   docker compose exec php php bin/console app:ai:benchmark
   ```

4. Open **[https://localhost](https://localhost)** (accept the local self-signed Caddy certificate) or **[http://localhost](http://localhost)**.

---

## 3. Environment Variables Reference

| Variable | Default | Description |
| :--- | :--- | :--- |
| `APP_ENV` | `dev` | Symfony environment (`dev`, `prod`, `test`). |
| `DATABASE_URL` | `postgresql://app:!ChangeMe!@database:5432/app?serverVersion=16&charset=utf8` | PostgreSQL 16 connection string. |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Async queue transport backed by PostgreSQL (`messenger_messages`). |
| `LOCK_DSN` | `flock` | Symfony Lock store used to prevent concurrent duplicate listing insertions. |
| `AI_PROVIDER` | `ollama` | LLM client driver: `ollama` (`/api/chat`) or `openai` (`/v1/chat/completions`). |
| `AI_BASE_URL` | `http://host.docker.internal:11434` | Base URL of the Ollama or OpenAI-compatible API server. |
| `AI_API_KEY` | *(empty)* | Bearer API key for `openai`-compatible endpoints (store in `.env.local` or k8s Secret). |
| `AI_MODEL` | `lfm2.5:8b` | Model name for search query expansion, Stage-1 gatekeeper, and Stage-2 appraisal. |
| `AI_VISION_MODEL` | `lfm2.5:8b` | Model name used when Vision AI photo inspection is active. |
| `AI_VISION_ENABLED` | `auto` | `auto` (probes Ollama `/api/show` for `"vision"`; defaults to `false` for OpenAI), `true`, or `false`. |
| `AI_MIN_REQUEST_INTERVAL_MS` | `0` | Minimum delay in milliseconds between LLM requests (`9000` recommended for Groq free tier). |
| `AI_TIMEOUT` | `120` | HTTP timeout in seconds per LLM request. |
| `AI_NUM_CTX` | `8192` | Ollama runtime context window size (`options.num_ctx`). |
| `SEARXNG_BASE_URL` | `http://searxng:8080` | Internal SearXNG URL for model datasheet verification (leave empty to disable). |
| `SEARXNG_TIMEOUT` | `8` | HTTP timeout in seconds for SearXNG queries. |
| `LISTING_PROVIDERS_ENABLED` | `leboncoin,vinted,backmarket,mock` | Comma-separated list of active marketplace providers. |
| `LISTING_PROVIDER_FALLBACK_TO_MOCK` | `true` (dev) / `false` (prod) | Whether network errors fall back to synthetic fixture listings. |
| `LISTING_PROVIDER_MAX_UNLIMITED_PAGES` | `15` (dev) / `5` (k8s prod) | Maximum pages crawled per query when `Max Ads / Query` is set to `Unlimited`. |

---

## 4. Production Deployment

### Option A: Production Docker Compose (External PostgreSQL)

Use [`compose.yaml`](../compose.yaml) with [`compose.prod.yaml`](../compose.prod.yaml) when deploying on a single server connected to an external PostgreSQL database configured in `.env.prod`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml build
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

### Option B: Production Kubernetes (`k3s` + Traefik)

The [`k8s/`](../k8s/) directory contains Kustomize manifests for deploying the FrankenPHP web server, a single-replica Messenger worker (kept at 1 replica to prevent parallel IP bursts from triggering marketplace anti-bot blocks), and an internal SearXNG service in namespace `listing-finder`:

```bash
# 1. Build the production image and import it into k3s containerd
docker build --target frankenphp_prod -t listing-finder:prod .
docker save listing-finder:prod | sudo k3s ctr images import -

# 2. Create namespace and secret
kubectl apply -f k8s/namespace.yaml
cp k8s/secret.example.yaml k8s/secret.local.yaml
# Edit k8s/secret.local.yaml with your real DATABASE_URL, APP_SECRET, and AI_API_KEY
kubectl apply -f k8s/secret.local.yaml

# 3. Apply manifests and restart deployments
kubectl apply -k k8s/
kubectl -n listing-finder rollout restart deployment/listing-finder-web deployment/listing-finder-worker
kubectl -n listing-finder rollout status deployment/listing-finder-web
kubectl -n listing-finder rollout status deployment/listing-finder-worker
```

See [`k8s/README.md`](../k8s/README.md) for additional cluster management commands.
