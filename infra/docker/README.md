# Deploy Pitchbar on a VPS (Docker / Portainer)

The local `docker-compose.yml` only starts **Postgres, Redis, Qdrant, and Mailpit**. Production uses `docker-compose.prod.yml`: FrankenPHP (Octane) + Horizon + scheduler + Inertia SSR, with Postgres and Redis on an internal network.

## 1. On the VPS

Clone the repo (or upload it), then:

```sh
cp .env.production.example .env
```

Fill in at least:

| Variable | How to generate |
|---|---|
| `APP_KEY` | `docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` |
| `WIDGET_JWT_SECRET` | `openssl rand -hex 32` |
| `DB_PASSWORD` | a long random string (required — the stack will not start without it) |
| `APP_URL` | public HTTPS origin, e.g. `https://app.yourdomain.com` |

Leave Cloudflare, OpenAI, mail, and Stripe **empty**. The in-app installer is **Settings → System** (Test buttons, then Save). Putting keys only in `.env` skips that UI for nothing — both paths work; the UI is the one the docs describe.

Leave `BROADCAST_CONNECTION=log` for the first boot (live inbox still works via polling).

## 2. Build and start

```sh
docker compose -f docker-compose.prod.yml up -d --build
```

First boot only prepares the empty app: migrations, default plan rows (if none exist), caches. It does **not** create users, provision Vectorize, or seed demo passwords.

```sh
docker compose -f docker-compose.prod.yml logs -f app
```

When `/up` is healthy, open the site and follow the same flow as `/documentation/installation`:

1. Sign up at `/register` — creates your workspace.
2. Complete **`/onboarding`** (URL → crawl → embed).
3. Promote yourself so **Settings → System** is visible:

```sh
docker compose -f docker-compose.prod.yml exec app php artisan pitchbar:make-admin you@example.com
```

4. In **Settings → System**, paste Cloudflare / mail / Stripe and click each **Test** button.
5. Restart workers so Horizon picks up the new keys:

```sh
docker compose -f docker-compose.prod.yml restart horizon
```

## 3. TLS / reverse proxy

Do **not** expose Postgres or Redis. Point Nginx Proxy Manager, Caddy, or Cloudflare Tunnel at **`app:80`** on the `pitchbar` Docker network (or at host port `APP_PORT`, default 8000).

Set `APP_URL=https://your-domain` so widget snippets, OAuth callbacks, and signed URLs are HTTPS.

## 4. Optional profiles

```sh
# Live inbox / human takeover over WebSockets
docker compose -f docker-compose.prod.yml --profile realtime up -d

# Self-hosted vectors instead of Cloudflare Vectorize
docker compose -f docker-compose.prod.yml --profile qdrant up -d
```

For Reverb, set `BROADCAST_CONNECTION=reverb` and proxy `wss://your-domain` (or a dedicated host) to the `reverb` service on port 8080.

For Qdrant, set `VECTOR_PROVIDER=qdrant` (compose already sets `QDRANT_URL=http://qdrant:6333` in `.env.production.example`).

## 5. Portainer

1. Stacks → Add stack → Repository (or paste `docker-compose.prod.yml`).
2. Set the compose path to `docker-compose.prod.yml`.
3. Create a `.env` in the clone **or** paste the same keys into the stack Environment editor. `env_file: .env` is optional (`required: false`); Laravel still needs those values in the container — easiest is a real `.env` next to the compose file.
4. Enable **Force pull / rebuild** after git updates so the image rebuilds.

## 6. Useful commands

```sh
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f horizon
docker compose -f docker-compose.prod.yml exec app php artisan horizon:status
docker compose -f docker-compose.prod.yml exec app php artisan pitchbar:audit-vectors
```

After pulling new code:

```sh
docker compose -f docker-compose.prod.yml up -d --build
```

The `app` container migrates on every start (safe). It never re-seeds demo users or overwrites plan prices you edited in the admin. Horizon / scheduler wait until `app` is healthy.

## Sizing

A single 4 GB VPS is enough for one workspace (Tryino Homes). The `app` service binds one host port; everything else stays on the internal `pitchbar` network.
