#!/bin/sh
# Installs WHMCS (first start) from the extracted official package in /whmcs-src, installs the
# registrar module from the ZIP in /e2e/module and starts Apache.
#
# The license key comes from the environment only (WHMCS_LICENSE_KEY); it ends up in
# configuration.php inside the container volume, never in a file of this repository.
set -e
WWW=/var/www/html
ADMIN_PASSWORD=e2e-password-123

[ -f /whmcs-src/init.php ] || { echo "[whmcs] WHMCS sources not found in /whmcs-src (run e2e/fetch-whmcs.sh)"; exit 1; }

if [ ! -f "$WWW/configuration.php" ]; then
  [ -n "$WHMCS_LICENSE_KEY" ] || { echo "[whmcs] WHMCS_LICENSE_KEY is not set"; exit 1; }
  echo "[whmcs] copying WHMCS sources"
  rsync -a --delete /whmcs-src/ "$WWW/"
  HASH=$(tr -dc 'a-zA-Z0-9' </dev/urandom | head -c 64)
  echo "[whmcs] running the WHMCS CLI installer"
  CONF=$(printf '{"admin":{"username":"admin","password":"%s"},"configuration":{"license":"%s","db_host":"db","db_username":"root","db_password":"root","db_name":"whmcs","cc_encryption_hash":"%s","mysql_charset":"utf8"}}' \
    "$ADMIN_PASSWORD" "$WHMCS_LICENSE_KEY" "$HASH")
  (cd "$WWW/install" && echo "$CONF" | php -f bin/installer.php -- -i -n -c) 2>&1 | sed "s/$WHMCS_LICENSE_KEY/<license>/g" | tail -20
  [ -f "$WWW/configuration.php" ] || { echo "[whmcs] installation failed"; exit 1; }
  rm -rf "$WWW/install"
  # System URL as seen by the browser (published port on 127.0.0.1)
  mysql -hdb -uroot -proot whmcs -e "INSERT INTO tblconfiguration (setting,value,created_at,updated_at) VALUES ('SystemURL','${WHMCS_URL}',NOW(),NOW()) ON DUPLICATE KEY UPDATE value=VALUES(value)" 2>/dev/null
  # No MarketConnect onboarding (it forces the Getting Started wizard to a Marketplace login)
  mysql -hdb -uroot -proot whmcs -e "UPDATE tblconfiguration SET value='0' WHERE setting='MarketConnectOnboardingRequired'" 2>/dev/null
  echo "[whmcs] installed"
fi

# Registrar module from the release ZIP layout (ZIP contains domainresellerapi/)
if ls /e2e/module/*.zip >/dev/null 2>&1; then
  ZIP=$(ls /e2e/module/*.zip | head -1)
  rm -rf "$WWW/modules/registrars/domainresellerapi"
  unzip -q -o "$ZIP" -d "$WWW/modules/registrars/"
  echo "[whmcs] module installed from $(basename "$ZIP")"
fi

# Test-only hook that records module parameters and API requests (/shared/drapi-e2e.jsonl)
if [ "${E2E_CAPTURE:-1}" = "1" ]; then
  cp /e2e/scripts/e2e-capture-hook.php "$WWW/includes/hooks/drapi_e2e_capture.php"
  touch /shared/drapi-e2e.jsonl && chmod 0666 /shared/drapi-e2e.jsonl
else
  rm -f "$WWW/includes/hooks/drapi_e2e_capture.php"
fi

chmod 1777 /shared
chown -R www-data:www-data "$WWW/templates_c" "$WWW/attachments" "$WWW/downloads" "$WWW/modules/registrars/domainresellerapi" 2>/dev/null || true
chmod 0444 "$WWW/configuration.php" 2>/dev/null || true
exec apache2-foreground
