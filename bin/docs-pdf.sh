#!/usr/bin/env bash
#
# Renders the README as docs/marketplace/user-guide.pdf — the PDF user guide the Adobe Commerce Marketplace
# listing wants. Markdown → HTML with marked, HTML → PDF with a headless Chrome.
# Run from the module root after a README change; the PDF is committed.
set -euo pipefail

CHROME=${CHROME:-"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"}
command -v "$CHROME" >/dev/null 2>&1 || [ -x "$CHROME" ] || { echo "Chrome not found; set CHROME=/path/to/chrome" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

{
  cat <<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>LedgerDirect for Magento 2</title>
<style>
  body { font: 11pt/1.45 -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; color: #1a1a1a; max-width: 46em; margin: 0 auto; }
  h1 { font-size: 22pt; margin-top: 0; } h2 { font-size: 15pt; margin-top: 1.6em; border-bottom: 1px solid #ccc; padding-bottom: .2em; } h3 { font-size: 12pt; }
  code, pre { font: 9.5pt/1.4 ui-monospace, Menlo, Consolas, monospace; } pre { background: #f4f4f4; padding: .6em .8em; overflow-x: auto; white-space: pre-wrap; }
  code { background: #f4f4f4; padding: 0 .2em; } pre code { background: none; padding: 0; }
  table { border-collapse: collapse; width: 100%; font-size: 10pt; } th, td { border: 1px solid #ccc; padding: .3em .5em; text-align: left; vertical-align: top; }
  img { max-width: 100%; } a { color: #1f5eff; text-decoration: none; }
  @page { margin: 18mm 16mm; }
</style></head><body>
HTML
  # Relative links (LICENSE, CHANGELOG.md, …) would point into the temporary directory, which is
  # gone when the PDF is read; they become links to the file on GitHub instead.
  npx --yes marked --gfm < README.md \
    | sed -E 's#href="([^"#/][^":]*)"#href="https://github.com/ledger-direct/ledger-direct-magento2/blob/main/\1"#g'
  echo '</body></html>'
} > "$work/readme.html"

# The screenshot is referenced relatively; the file is next to the README, so copy it beside the HTML.
cp payment_page.png "$work/payment_page.png" 2>/dev/null || true

"$CHROME" --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="docs/marketplace/user-guide.pdf" "file://$work/readme.html" >/dev/null 2>&1
ls -la docs/marketplace/user-guide.pdf
