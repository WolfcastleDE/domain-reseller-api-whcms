#!/usr/bin/env bash
# End-to-end test of the registrar module inside a real WHMCS installation against the
# Domain Reseller API backend in sandbox mode. See e2e/README.md.
#
#   ./e2e/run.sh            start, install and run every test (keeps the environment running)
#   ./e2e/run.sh setup      start + install + configure only (for manual testing)
#   ./e2e/run.sh test       run the tests against a running environment
#   ./e2e/run.sh reinstall  rebuild/reinstall the module ZIP in the running WHMCS
#   ./e2e/run.sh shots      marketplace screenshots (needs a tested environment)
#   ./e2e/run.sh down       remove containers and volumes
#
# Environment:
#   WHMCS_LICENSE_KEY  WHMCS (development) license key - required, never stored in this repository
#   BACKEND_PATH       Domain Reseller API repository (default: ~/Projects/domain-reseller-api)
#   MODULE             local (default: ZIP built from this checkout like the release workflow)
#                      or a release tag such as v0.9.1 (downloaded with gh)
#   WHMCS_CHANNEL      stable (default) | lts         PHP_VERSION   8.3 (default) | 8.2 | 8.4
#   WHMCS_PORT         18081 (default)  API_PORT 13001 (default)  E2E_SUBNET_PREFIX 172.26.0
set -euo pipefail

cd "$(dirname "$0")"
export WHMCS_PORT="${WHMCS_PORT:-18081}" API_PORT="${API_PORT:-13001}" PHP_VERSION="${PHP_VERSION:-8.3}"
MODULE="${MODULE:-local}"
COMPOSE=(docker compose -f docker-compose.yml)
URL="http://127.0.0.1:${WHMCS_PORT}"
failed=0

if [ -z "${BACKEND_PATH:-}" ]; then
  for candidate in ../../domain-reseller-api "$HOME/Projects/domain-reseller-api"; do
    if [ -f "$candidate/packages/backend/package.json" ]; then BACKEND_PATH="$(cd "$candidate" && pwd)"; break; fi
  done
fi
export BACKEND_PATH="${BACKEND_PATH:-}"

need_backend() {
  [ -f "${BACKEND_PATH}/packages/backend/package.json" ] || { echo "Domain Reseller API backend not found. Set BACKEND_PATH=/path/to/domain-reseller-api" >&2; exit 1; }
}

whmcs_version() {
  WHMCS_VERSION="$(./fetch-whmcs.sh)"
  export WHMCS_VERSION
  echo "WHMCS ${WHMCS_VERSION} (SHA-256 verified), PHP ${PHP_VERSION}"
}

# The module ZIP in .cache/module/ (installed by whmcs-entrypoint.sh)
module_zip() {
  mkdir -p .cache/module && rm -f .cache/module/*   # keep the directory: it is bind-mounted
  if [ "$MODULE" = "local" ]; then
    local tmp; tmp="$(mktemp -d)"
    cp -r ../modules/registrars/domainresellerapi "$tmp/" && cp ../LICENSE "$tmp/domainresellerapi/LICENSE"
    (cd "$tmp" && zip -qr domainresellerapi-local.zip domainresellerapi)
    mv "$tmp/domainresellerapi-local.zip" .cache/module/ && rm -rf "$tmp"
  else
    gh release download "$MODULE" -R WolfcastleDE/domain-reseller-api-whcms -p 'domainresellerapi-*.zip' -D .cache/module --clobber
  fi
  echo "Module: $(ls .cache/module)"
}

php_run() { "${COMPOSE[@]}" exec -T -u www-data whmcs php "/e2e/scripts/$1.php" || failed=1; }
browser() {
  mkdir -p artifacts
  local out rc=0
  out="$("${COMPOSE[@]}" --profile tests run --rm -T -e TEST="$1" playwright 2>&1)" || rc=$?
  grep -v ' Container ' <<<"$out" || true
  [ "$rc" -eq 0 ] || failed=1
}

up() {
  need_backend
  [ -n "${WHMCS_LICENSE_KEY:-}" ] || { echo "WHMCS_LICENSE_KEY is not set" >&2; exit 1; }
  whmcs_version
  module_zip
  echo "Starting environment (first start installs WHMCS and the backend, a few minutes)…"
  "${COMPOSE[@]}" build -q whmcs
  "${COMPOSE[@]}" up -d --quiet-pull db api whmcs
  echo -n "Waiting for WHMCS"
  for _ in $(seq 1 150); do
    if curl -fs -o /dev/null "${URL}/admin/login.php"; then echo " ready"; break; fi
    echo -n "."; sleep 4
  done
  curl -fs -o /dev/null "${URL}/admin/login.php" || { echo; "${COMPOSE[@]}" logs --tail 60 whmcs api; exit 1; }
}

configure() {
  browser license                 # EULA + license state
  browser registrar               # System Settings -> Domain Registrars (activate, configure, connection test)
  php_run setup                   # EUR, payment gateway, test client
  browser tldsync                 # Utilities -> Registrar TLD Sync
  php_run setup                   # DNS management / ID protection for the imported TLDs
  browser lookup                  # lookup provider, suggestion and spotlight TLDs
}

reinstall() {
  module_zip
  "${COMPOSE[@]}" exec -T whmcs sh -c 'rm -rf modules/registrars/domainresellerapi && unzip -q -o /e2e/module/*.zip -d modules/registrars/ && chown -R www-data:www-data modules/registrars/domainresellerapi && cp /e2e/scripts/e2e-capture-hook.php includes/hooks/drapi_e2e_capture.php'
}

tests() {
  php_run register                # order + register .com/.ai/.it/.de
  php_run manage                  # nameservers, lock, EPP, contacts, ID protection, glue, DNS, renew, sync, buttons
  php_run transfer                # transfer + transfer sync
  php_run redemption              # expiry sync, restore, delete/cancel deletion
  php_run contacts                # owner change setting
  browser client                  # client area
  browser admin-domain            # admin domain page + custom buttons
  browser search                  # cart search, suggestions, spotlight bulk check
}

summary() {
  cat <<INFO

WHMCS:  ${URL}/admin   admin / e2e-password-123
        ${URL}/        kunde@e2e.test / e2e-password-123
API:    http://127.0.0.1:${API_PORT}/swagger   (sandbox reseller reseller@e2e.test / e2e-password)
Logs:   module params + API requests in the shared volume (/shared/drapi-e2e.jsonl in the whmcs container)
Shots:  e2e/artifacts/
Stop:   ./e2e/run.sh down
INFO
}

case "${1:-all}" in
  down) "${COMPOSE[@]}" --profile tests down -v --remove-orphans; rm -rf artifacts ;;
  setup) up; configure; summary ;;
  test) tests ;;
  reinstall) reinstall ;;
  shots) php_run demo; browser screenshots ;;
  all) up; configure; tests; php_run demo; browser screenshots; summary ;;
  *) echo "usage: $0 [all|setup|test|reinstall|shots|down]"; exit 2 ;;
esac

if [ "$failed" -ne 0 ]; then echo -e "\n\033[31mSome tests failed.\033[0m"; exit 1; fi
echo -e "\n\033[32mAll tests passed.\033[0m"
