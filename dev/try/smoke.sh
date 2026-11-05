#!/usr/bin/env bash
#
# Smoke test of a running try stack (dev/try/compose.yaml), the way a visitor would use it: the
# shop answers, the demo article is in its category, a guest order with XRP gets a payment page that
# renders the markup contract of @ledger-direct/payment-ui, and the status endpoint answers the
# payment-status contract with `waiting` (case PS-01 of the core's catalogue). Then the demo admin
# opens Stores → Configuration → Payment Methods, which shows the module's settings.
#
#   dev/try/smoke.sh [base url]          default http://localhost:8088
#
# Needs curl and jq. Exits non-zero on the first failure.
set -euo pipefail
BASE=${1:-http://localhost:${LD_TRY_PORT:-8088}}
BASE=${BASE%/}
ADMIN_USER=${LD_ADMIN_USER:-demo}
ADMIN_PASSWORD=${LD_ADMIN_PASSWORD:-demo-ledgerdirect-1}
SKU=ledger-direct-demo

pass() { printf 'ok    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
rest() { curl -fsS -H 'Content-Type: application/json' -H 'Accept: application/json' "$@"; }

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
[ "$code" = 200 ] || fail "storefront answered $code"
pass "storefront answers"

# Read the page first: `curl | grep -q` under pipefail fails whenever grep exits before curl has
# written everything (SIGPIPE), even though the text is there.
category=$(curl -fsS "$BASE/ledger-direct-demo.html")
grep -q 'LedgerDirect demo article' <<<"$category" || fail "the demo category does not list the demo article"
pass "demo category lists the demo article"

# The miniatures from dev/try/catalog/catalog.json, in their category and on the home page.
catalog="$(dirname "$0")/catalog/catalog.json"
miniatures=$(curl -fsS "$BASE/miniatures.html")
home=$(curl -fsS "$BASE/")
while IFS= read -r name; do
  html_name=$(sed 's/&/\&amp;/g' <<<"$name")
  grep -qF "$html_name" <<<"$miniatures" || fail "the Miniatures category lacks \"$name\""
  grep -qF "$html_name" <<<"$home" || fail "the home page lacks \"$name\""
done < <(jq -r '.products[]."en-GB".name' "$catalog")
pass "home page and the Miniatures category show $(jq '.products | length' "$catalog") miniatures"

address='{"firstname":"Try","lastname":"Visitor","street":["1 Testnet Way"],"city":"Berlin","postcode":"10115","country_id":"DE","telephone":"000","email":"visitor@example.invalid"}'
cart=$(rest -X POST "$BASE/rest/V1/guest-carts" | jq -r .)
rest -X POST "$BASE/rest/V1/guest-carts/$cart/items" -d "{\"cartItem\":{\"sku\":\"$SKU\",\"qty\":1,\"quote_id\":\"$cart\"}}" >/dev/null
shipping=$(rest -X POST "$BASE/rest/V1/guest-carts/$cart/estimate-shipping-methods" -d "{\"address\":$address}" \
  | jq -c '[.[] | select(.available)] | min_by(.amount)')
[ "$shipping" != null ] || fail "no shipping method available"
rest -X POST "$BASE/rest/V1/guest-carts/$cart/shipping-information" -d "{\"addressInformation\":{\"shipping_address\":$address,\"billing_address\":$address,\"shipping_carrier_code\":$(jq '.carrier_code' <<<"$shipping"),\"shipping_method_code\":$(jq '.method_code' <<<"$shipping")}}" >/dev/null
order=$(rest -X POST "$BASE/rest/V1/guest-carts/$cart/payment-information" -d "{\"email\":\"visitor@example.invalid\",\"paymentMethod\":{\"method\":\"xrp_payment\"}}" | jq -r .)
[[ "$order" =~ ^[0-9]+$ ]] || fail "placing the order failed: $order"

token=$(rest -X POST "$BASE/rest/V1/integration/admin/token" -d "{\"username\":\"$ADMIN_USER\",\"password\":\"$ADMIN_PASSWORD\"}" | jq -r .)
read -r increment key total < <(curl -fsS -H "Authorization: Bearer $token" "$BASE/rest/V1/orders/$order?fields=increment_id,protect_code,grand_total" \
  | jq -r '"\(.increment_id) \(.protect_code) \(.grand_total)"')
pass "guest order $increment placed with XRP, total $total EUR"

page=$(mktemp)
code=$(curl -s -o "$page" -w '%{http_code}' "$BASE/ledger-direct/payment/index?id=$order&key=$key")
[ "$code" = 200 ] || fail "payment page answered $code"
for attribute in 'data-ld-page' 'data-ld-state="waiting"' 'data-ld-amount-requested="' 'data-ld-asset="XRP"' 'data-ld-poll-url="' 'data-ld-account' 'data-ld-tag'; do
  grep -q -- "$attribute" "$page" || fail "payment page lacks $attribute"
done
amount=$(grep -o 'data-ld-amount-requested="[^"]*"' "$page" | head -1 | cut -d'"' -f2)
pass "payment page renders the markup contract (amount $amount XRP)"

status=$(curl -fsS -H 'Accept: application/json' "$BASE/ledger-direct/payment/status?id=$order&key=$key")
[ "$(jq -r .state <<<"$status")" = waiting ] || fail "status endpoint: $status"
[ "$(jq -r '.seconds_left > 0' <<<"$status")" = true ] || fail "status endpoint without seconds_left: $status"
pass "status endpoint: waiting, $(jq -r .seconds_left <<<"$status") s left (PS-01)"

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/ledger-direct/payment/status?id=$order&key=wrong")
[ "$code" = 403 ] || fail "a wrong key got $code instead of 403"
pass "a wrong key is refused with 403 (PS-07)"

# The admin's Payment Methods page, signed in as the demo admin. Up to 1.1.5 it failed to render in
# the default scope: Magento_Paypal serves the section as payment_<country>, and the email template
# field asked for a default template named after that path. Admin links carry secret keys, so the
# page is reached through the links the admin itself renders: dashboard → configuration → payment.
# Magento sets the admin cookie for domain=localhost, which curl's cookie engine refuses (no dot in
# the domain), so the session cookie is carried by hand and redirects are followed here.
admin_cookie=
admin_page=$(mktemp)
admin() { # admin <url> [curl args…]: the final page in $admin_page, its status code in $admin_code
  local url=$1 headers cookie location
  shift
  headers=$(mktemp)
  for _ in 1 2 3 4 5; do
    admin_code=$(curl -sS --max-time 120 -o "$admin_page" -D "$headers" -w '%{http_code}' \
      ${admin_cookie:+-H "Cookie: admin=$admin_cookie"} "$@" "$url")
    cookie=$(grep -io '^set-cookie: admin=[^;]*' "$headers" | tail -1 | cut -d= -f2- || true)
    [ -z "$cookie" ] || admin_cookie=$cookie
    location=$(grep -i '^location:' "$headers" | tail -1 | cut -d' ' -f2- | tr -d '\r' || true)
    [ -n "$location" ] || return 0
    url=$location
    set --
  done
  fail "admin: more than 5 redirects from $url"
}
admin "$BASE/admin/"
form_key=$(grep -o 'name="form_key" type="hidden" value="[^"]*"' "$admin_page" | head -1 | sed 's/.*value="//; s/"$//')
[ -n "$form_key" ] || fail "admin login page without form key"
admin "$BASE/admin/" -X POST --data-urlencode "form_key=$form_key" \
  --data-urlencode "login[username]=$ADMIN_USER" --data-urlencode "login[password]=$ADMIN_PASSWORD"
config_url=$(grep -o "$BASE/admin/admin/system_config/index/key/[0-9a-f]*/" "$admin_page" | head -1)
[ -n "$config_url" ] || fail "admin login failed, or the dashboard has no configuration link"
admin "$config_url"
payment_url=$(grep -o "$BASE/admin/admin/system_config/edit/section/payment/key/[0-9a-f]*/" "$admin_page" | head -1)
[ -n "$payment_url" ] || fail "the configuration page has no link to Payment Methods"
admin "$payment_url"
[ "$admin_code" = 200 ] || fail "admin Payment Methods answered $admin_code"
for group in 'Ledger Direct / XRPL' 'Ledger Direct - XRP (XRPL)' 'Ledger Direct - RLUSD (XRPL)' 'Ledger Direct - USDC (XRPL)'; do
  grep -qF "$group" "$admin_page" || fail "admin Payment Methods lacks the group \"$group\""
done
pass "admin Payment Methods shows the module's four groups"

printf '\npayment page: %s\n' "$BASE/ledger-direct/payment/index?id=$order&key=$key"
