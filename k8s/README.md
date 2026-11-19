# Deploying Listing Finder on Kubernetes (k3s + Traefik)

This folder contains the Kubernetes manifests to deploy **Listing Finder** (FrankenPHP web dashboard, Symfony Messenger/Scheduler worker, and an internal **SearXNG** instance for domain-agnostic product datasheet lookups) on a k3s cluster using the built-in Traefik ingress controller and your shared in-cluster PostgreSQL service (`postgres-postgresql.postgres.svc.cluster.local`).

## 1. Build & Import the Production Image into k3s

```bash
docker build --target frankenphp_prod -t listing-finder:prod .
docker save listing-finder:prod | sudo k3s ctr images import -
```

## 2. Create the Secret (`listing-finder-secret`)

Copy `k8s/secret.example.yaml` to `k8s/secret.local.yaml` (git-ignored), fill in your real `APP_SECRET`, `DATABASE_URL`, and Mercure JWT secret, and apply it:

```bash
kubectl apply -f k8s/namespace.yaml
cp k8s/secret.example.yaml k8s/secret.local.yaml
# Edit k8s/secret.local.yaml with your real values
kubectl apply -f k8s/secret.local.yaml
```

## 3. Deploy the Application

```bash
kubectl apply -k k8s/
kubectl -n listing-finder rollout restart deployment/listing-finder-web deployment/listing-finder-worker
kubectl -n listing-finder rollout status deployment/listing-finder-web
kubectl -n listing-finder rollout status deployment/listing-finder-worker
kubectl -n listing-finder rollout status deployment/searxng
```

## 4. Access the Dashboard

- Via DNS (if configured in your local DNS / Technitium): `https://listing-finder.zt.home.arpa` or `http://listing-finder.zt.home.arpa`
- Via your k3s node IP directly (through Traefik): `https://<node-ip>/` or `http://<node-ip>/`

## 5. Run Console Commands in k8s

```bash
kubectl -n listing-finder exec -it deploy/listing-finder-web -- php bin/console app:search:results
kubectl -n listing-finder exec -it deploy/listing-finder-web -- php bin/console app:search:start --price=50 "canon printer ax380"
```

## 6. Stop Docker Compose Production Containers (to avoid port conflicts)

If you previously ran the app with Docker Compose on ports 80/443, stop it before relying on k3s Traefik:

```bash
docker compose -f compose.yaml -f compose.prod.yaml down
```
