#!/usr/bin/env bash
#
# Every start of the try-it shop:
# - first start: install Magento against the stack's database and search engine
# - apply the LedgerDirect settings (receiving account, payment methods) — on every start, so a
#   changed LD_TESTNET_ACCOUNT takes effect without a reset
# - first start: the demo category and article
# - after an image update: setup:upgrade
# - then Magento's cron in the background, and the shop on PHP's built-in server
#
# The shop's state lives in the stack's volumes (database, search index, app/etc, var, pub/media), so
# recreating this container keeps it. `docker compose down -v` is the reset.
set -euo pipefail
cd /var/www/html

: "${LD_BASE_URL:=http://localhost:8088/}"
: "${LD_DB_HOST:=db}"
: "${LD_DB_NAME:=magento}"
: "${LD_DB_USER:=magento}"
: "${LD_DB_PASSWORD:=magento}"
: "${LD_OPENSEARCH_HOST:=opensearch}"
: "${LD_OPENSEARCH_PORT:=9200}"
# A demo login for a shop that only listens on this machine. Not a secret, never reuse it.
: "${LD_ADMIN_USER:=demo}"
: "${LD_ADMIN_PASSWORD:=demo-ledgerdirect-1}"
: "${LD_PORT:=8080}"

INSTALLED=var/.ledger-direct-try-installed      # setup:install completed
CATALOG=var/.ledger-direct-try-catalog          # demo category and article created
BUILD=var/.ledger-direct-try-build              # the image the shop was last set up with
SETUP=/usr/local/share/ledger-direct
log() { printf '[ledger-direct-try] %s\n' "$*"; }

wait_for() {
  local what=$1 tries=120
  shift
  until "$@" >/dev/null 2>&1; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then log "gave up waiting for $what"; exit 1; fi
    sleep 2
  done
}
db_ready() {
  php -r 'new PDO("mysql:host=".getenv("LD_DB_HOST").";dbname=".getenv("LD_DB_NAME"), getenv("LD_DB_USER"), getenv("LD_DB_PASSWORD"));'
}
db_has_shop() {
  php -r '$p=new PDO("mysql:host=".getenv("LD_DB_HOST").";dbname=".getenv("LD_DB_NAME"), getenv("LD_DB_USER"), getenv("LD_DB_PASSWORD"));
          exit($p->query("SHOW TABLES LIKE \"core_config_data\"")->fetchColumn() === false ? 1 : 0);'
}
search_ready() {
  curl -fs "http://${LD_OPENSEARCH_HOST}:${LD_OPENSEARCH_PORT}/_cluster/health?wait_for_status=yellow&timeout=2s"
}
export LD_DB_HOST LD_DB_NAME LD_DB_USER LD_DB_PASSWORD

wait_for "the database" db_ready
wait_for "the search engine" search_ready
started=$(date +%s)

if [ ! -f "$INSTALLED" ]; then
  cleanup=()
  if [ -f app/etc/env.php ]; then
    # A previous first start got as far as writing the configuration but did not finish: nothing in
    # the database belongs to a usable shop yet, so starting over is safe.
    log "an earlier installation did not finish; starting it over"
    cleanup=(--cleanup-database)
  elif db_has_shop; then
    # A shop in the database, but this container has no configuration for it (e.g. its app/etc volume
    # was removed). Installing now would wipe that shop; refuse instead.
    log "the database already holds a shop, but its configuration (app/etc) is missing."
    log "reset the whole stack with: docker compose down -v"
    exit 1
  fi

  log "installing Magento (about a minute)"
  bin/magento setup:install --no-interaction \
    --base-url="$LD_BASE_URL" --use-rewrites=1 --use-secure=0 --use-secure-admin=0 \
    --db-host="$LD_DB_HOST" --db-name="$LD_DB_NAME" --db-user="$LD_DB_USER" --db-password="$LD_DB_PASSWORD" \
    --search-engine=opensearch --opensearch-host="$LD_OPENSEARCH_HOST" --opensearch-port="$LD_OPENSEARCH_PORT" \
    --admin-firstname=Demo --admin-lastname=Admin --admin-email=demo@example.invalid \
    --admin-user="$LD_ADMIN_USER" --admin-password="$LD_ADMIN_PASSWORD" --backend-frontname=admin \
    --language=en_US --currency=EUR --timezone=Europe/Berlin \
    --disable-modules=Magento_TwoFactorAuth,Magento_AdminAdobeImsTwoFactorAuth \
    "${cleanup[@]}" | tail -n 2
  touch "$INSTALLED"
  cp "$SETUP/build-id" "$BUILD"
elif ! cmp -s "$SETUP/build-id" "$BUILD" 2>/dev/null; then
  log "the image changed since the last start: setup:upgrade"
  bin/magento setup:upgrade --keep-generated >/dev/null
  cp "$SETUP/build-id" "$BUILD"
fi

php "$SETUP/install.php" config

if [ ! -f "$CATALOG" ]; then
  php "$SETUP/install.php" catalog
  bin/magento indexer:reindex 2>&1 | grep -v "has been rebuilt successfully" || true
  touch "$CATALOG"
fi
bin/magento cache:flush >/dev/null
log "ready in $(( $(date +%s) - started )) s"

# Magento's cron: the settlement job settles orders whose payment arrived while no page was open.
( while sleep 60; do php bin/magento cron:run >/dev/null 2>&1 || true; done ) &

log "shop:  ${LD_BASE_URL}"
log "admin: ${LD_BASE_URL}admin  (demo login ${LD_ADMIN_USER} / ${LD_ADMIN_PASSWORD} — local demo only)"
log "receiving account: $(cat var/.ledger-direct-try-account 2>/dev/null || echo unknown)"
export PHP_CLI_SERVER_WORKERS="${LD_WORKERS:-4}"
exec php -S "0.0.0.0:${LD_PORT}" -t pub phpserver/router.php
