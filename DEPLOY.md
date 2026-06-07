# pos_merchant — production deploy (posmerchant.mithqal.net)

Runs on the VPS as a Docker Compose stack that **joins the existing external
`charity_net` network** and shares the charity Postgres (`chariyt-db` /
`charity_db`) with the charity API and pos_admin.

> **pos_merchant owns NO production schema.** pos_admin owns the `pos_*` tables;
> pos_merchant only reads/writes them. There is therefore **no migrate step** —
> do NOT run `php artisan migrate` against the production DB from pos_merchant.

## Prerequisites

- External network `charity_net` exists and the shared Postgres (`chariyt-db` /
  `charity_db`) is up on it.
- pos_admin has already been deployed and migrated (it owns the schema).
- A host reverse proxy / TLS terminator is in front.

## 1. Configure `.env`

```bash
cp src/.env.production.example src/.env
# Edit src/.env and set:
#   APP_KEY     -> paste pos_admin's APP_KEY VERBATIM (shared encryption key).
#                  Do NOT run key:generate here.
#   DB_PASSWORD -> the charity_db password.
```

## 2. Build code + assets

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml --profile build run --rm composer
docker compose -f docker-compose.prod.yml --profile build run --rm node-build
docker compose -f docker-compose.prod.yml --profile init  run --rm init-perms   # first deploy only
```

## 3. Start + cache

```bash
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml --profile deploy run --rm deploy
```

## 4. Reverse proxy

The `nginx` service exposes `:80` on `charity_net` under the alias
**`pos-merchant-web`**. Point the host proxy at it:

```nginx
server {
    server_name posmerchant.mithqal.net;
    # ... your TLS / certbot config ...
    location / {
        proxy_pass http://pos-merchant-web:80;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;   # required — Laravel trusts this
        proxy_set_header X-Forwarded-Host  $host;
    }
}
```

## Updating a release

```bash
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml --profile build  run --rm composer
docker compose -f docker-compose.prod.yml --profile build  run --rm node-build
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml --profile deploy run --rm deploy
```
