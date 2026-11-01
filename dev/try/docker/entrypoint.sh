#!/usr/bin/env bash
#
# First start: install Magento against the stack's database and search engine, configure LedgerDirect
# on the testnet and add a demo article. Every start: run Magento's cron in the background and serve
# the shop with PHP's built-in server — enough for trying the module, not a production setup.
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

MARKER=var/.ledger-direct-try-installed
log() { printf '[ledger-direct-try] %s\n' "$*"; }

wait_for() {
  local what=$1 tries=120
  shift
  until "$@" >/dev/null 2>&1; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then log "gave up waiting for $what"; exit 1; fi
    sleep 2
  done
  log "$what is up"
}

db_ready() {
  php -r '$p=getenv("LD_DB_PASSWORD")?:"magento"; new PDO("mysql:host=".(getenv("LD_DB_HOST")?:"db").";dbname=".(getenv("LD_DB_NAME")?:"magento"), getenv("LD_DB_USER")?:"magento", $p);'
}
search_ready() {
  curl -fs "http://${LD_OPENSEARCH_HOST}:${LD_OPENSEARCH_PORT}/_cluster/health?wait_for_status=yellow&timeout=2s"
}

if [ ! -f "$MARKER" ]; then
  started=$(date +%s)
  wait_for "the database" db_ready
  wait_for "the search engine" search_ready
  rm -f app/etc/env.php

  log "installing Magento (about a minute)"
  bin/magento setup:install --no-interaction \
    --base-url="$LD_BASE_URL" --use-rewrites=1 --use-secure=0 --use-secure-admin=0 \
    --db-host="$LD_DB_HOST" --db-name="$LD_DB_NAME" --db-user="$LD_DB_USER" --db-password="$LD_DB_PASSWORD" \
    --search-engine=opensearch --opensearch-host="$LD_OPENSEARCH_HOST" --opensearch-port="$LD_OPENSEARCH_PORT" \
    --admin-firstname=Demo --admin-lastname=Admin --admin-email=demo@example.invalid \
    --admin-user="$LD_ADMIN_USER" --admin-password="$LD_ADMIN_PASSWORD" --backend-frontname=admin \
    --language=en_US --currency=EUR --timezone=Europe/Berlin \
    --disable-modules=Magento_TwoFactorAuth,Magento_AdminAdobeImsTwoFactorAuth \
    --cleanup-database | tail -n 2

  log "configuring LedgerDirect on the testnet"
  php /usr/local/share/ledger-direct/install.php
  bin/magento indexer:reindex 2>&1 | grep -v "has been rebuilt successfully" || true
  bin/magento cache:flush >/dev/null
  touch "$MARKER"
  log "installed in $(( $(date +%s) - started )) s"
fi

# Magento's cron: the settlement job settles orders whose payment arrived while no page was open.
( while sleep 60; do php bin/magento cron:run >/dev/null 2>&1 || true; done ) &

log "shop:  ${LD_BASE_URL}"
log "admin: ${LD_BASE_URL}admin  (demo login ${LD_ADMIN_USER} / ${LD_ADMIN_PASSWORD} — local demo only)"
log "receiving account: $(cat var/.ledger-direct-try-account 2>/dev/null || echo unknown)"
export PHP_CLI_SERVER_WORKERS="${LD_WORKERS:-4}"
exec php -S "0.0.0.0:${LD_PORT}" -t pub phpserver/router.php
