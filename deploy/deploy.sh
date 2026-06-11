#!/bin/bash
# Production deploy for pos_merchant — executed ON THE VPS by the GitHub
# Actions `deploy` job after tests pass (or by hand). Assumes the repo was
# just `git pull`ed. NO migrate step: pos_admin owns the shared schema.
set -euo pipefail
cd "$(dirname "$0")/.."
C="docker-compose.prod.yml"

docker compose -f "$C" build
docker compose -f "$C" --profile build run --rm composer
docker compose -f "$C" --profile build run --rm node-build
docker compose -f "$C" up -d
timeout 300 docker compose -f "$C" --profile deploy run --rm deploy
docker restart pos_merchant-pos_merchant-1

sleep 6
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 https://posmerchant.mithqal.net/login)
echo "health: HTTP $code"
[ "$code" = "200" ] || { echo "FAIL: health check"; exit 1; }
errs=$(docker logs --since 1m pos_merchant-pos_merchant-1 2>&1 | grep -ciE "fatal error|exception" || true)
echo "fresh log errors: $errs"
[ "$errs" -eq 0 ] || { echo "FAIL: errors right after deploy"; exit 1; }
echo "deploy OK"
