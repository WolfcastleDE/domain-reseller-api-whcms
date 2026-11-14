#!/bin/sh
# Copies the backend sources (read-only mount) into the container, migrates, seeds,
# creates a sandbox reseller account + API key and starts the API.
set -e

[ -f /src/packages/backend/package.json ] || { echo "[api] backend sources not mounted (BACKEND_PATH)"; exit 1; }
echo "[api] syncing sources"
cd /src
tar --exclude=./node_modules --exclude=./packages/*/node_modules --exclude=./.git --exclude=./.claude --exclude=./packages/frontend -cf - . | (cd /app && tar -xf -)

cd /app
echo "[api] installing dependencies"
bun install >/dev/null

cd /app/packages/backend
echo "[api] migrating"
bunx drizzle-kit migrate >/dev/null
bun run src/db/migrate.ts >/dev/null
bun run src/seed.ts >/dev/null

if [ ! -s /shared/api_key ]; then
  echo "[api] creating reseller account"
  cp /e2e/e2e-backend-setup.ts ./e2e-backend-setup.ts
  bun run e2e-backend-setup.ts | sed -n 's/^API_KEY=//p' > /shared/api_key
fi

echo "[api] starting"
exec bun run src/index.ts
