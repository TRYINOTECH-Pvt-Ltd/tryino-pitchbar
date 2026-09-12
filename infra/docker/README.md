# Deploy Pitchbar on a VPS (Hostinger Docker Manager)

Production compose is `docker-compose.yml`. Local Postgres/Redis/Qdrant/Mailpit is `docker-compose.local.yml`.

Do **not** run `migrate` or seeders. The first person who registers becomes admin and finishes setup in **Settings → System**.

## Hostinger Docker Manager (short)

1. **DNS** — A record `pitch` → your VPS IPv4. Open firewall ports **80**, **443**, **22**.
2. **Docker Manager** — hPanel → VPS → Docker Manager. Install the Docker template if asked (OS change **wipes** the VPS — snapshot first).
3. **Clone into the Docker Manager project folder** (terminal):

```sh
cd /docker
git clone https://github.com/TRYINOTECH-Pvt-Ltd/tryino-pitchbar.git tryino-pitchbar
cd tryino-pitchbar
cp .env.production.example .env
nano .env
```

Fill at least:

| Variable | Value |
|---|---|
| `APP_URL` | `https://pitch.tryinotech.com` |
| `APP_DOMAIN` | `pitch.tryinotech.com` |
| `APP_KEY` | `docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` |
| `WIDGET_JWT_SECRET` | `openssl rand -hex 32` |
| `DB_PASSWORD` | a long random string (required) |

Leave Cloudflare, mail, and Stripe empty.

4. **Start** (same folder — so Docker can see the `Dockerfile`):

```sh
docker compose up -d --build
docker compose logs -f app
```

Wait until `/up` is healthy, then Ctrl+C. First build takes several minutes.

5. Open **https://pitch.tryinotech.com/register**, create your account, finish onboarding.
6. **Settings → System** — paste Cloudflare / mail / Stripe, click each **Test**, Save. Then:

```sh
docker compose restart horizon
```

Use Docker Manager → Projects for logs, restart, and the container terminal. Do **not** use One-click deploy or **Compose from URL** (that only copies YAML and causes **Docker project not found**).

If an empty `tryino-pitchbar` project already exists, delete it in Docker Manager first, then clone as in step 3.

## TLS (Traefik — no Caddy)

Keep the Hostinger **Traefik** project running (`traefik-k0ce`). It uses **host networking** (ports 80/443 on the VPS) and discovers Pitchbar via Docker labels. There is no `traefik-proxy` network.

Postgres must be **`pgvector/pgvector:pg16`** (not `postgres:16-alpine`) so `CREATE EXTENSION vector` succeeds. If you already started with Alpine, wipe the empty volume once:

```sh
docker compose down
docker volume rm tryino-pitchbar_pitchbar-pgdata
docker compose up -d --build
```

## Optional profiles

```sh
docker compose --profile realtime up -d    # live inbox WebSockets
docker compose --profile qdrant up -d      # self-hosted vectors
```

For Reverb, set `BROADCAST_CONNECTION=reverb` and keep `REVERB_HOST` equal to `APP_DOMAIN`.

## Updates

```sh
cd /docker/tryino-pitchbar
git pull
docker compose up -d --build
```

Named volumes (Postgres, Redis, storage) are kept. Traefik keeps the TLS certs.

## Sizing

A single 4 GB VPS is enough for one workspace. Postgres and Redis stay on the internal `pitchbar` network.
