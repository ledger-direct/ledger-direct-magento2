#!/usr/bin/env bash
# Writes docs_only=true|false and runtime=true|false to $GITHUB_OUTPUT (or stdout when unset).
# Reads the changed files from git, or from CHANGED_FILES (newline-separated) for tests.
set -euo pipefail

docs='^([^/]+/)*[^/]+\.md$|^docs/|^readme\.txt$|^CHANGELOG|^LICENSE|^\.coderabbit\.yaml$|^\.github/(ISSUE_TEMPLATE/|PULL_REQUEST_TEMPLATE|CODEOWNERS|dependabot\.yml)'
# Changes that never reach the shop: tests and the configs of the test tools, CI workflows other than
# the try stack's, editor and git housekeeping.
not_runtime='^tests?/|^phpunit(\.xml)?(\.dist)?$|^phpstan[^/]*$|^\.phpcs[^/]*$|^phpcs\.xml(\.dist)?$|^\.php-cs-fixer[^/]*$|^\.editorconfig$|^\.gitattributes$|^\.gitignore$'

changed_files() {
  if [ -n "${CHANGED_FILES-}" ]; then printf '%s\n' "$CHANGED_FILES"; return; fi
  case "${EVENT-}" in
    pull_request)
      # actions/checkout checks out the merge commit; its first parent is the base branch.
      git diff --name-only HEAD^1 HEAD ;;
    push)
      if [ -z "${BEFORE-}" ] || [ "$BEFORE" = "0000000000000000000000000000000000000000" ] \
         || ! git cat-file -e "$BEFORE^{commit}" 2>/dev/null; then
        echo "::notice::no base to diff against; classifying as runtime" >&2; echo '*'; return
      fi
      git diff --name-only "$BEFORE" "$AFTER" ;;
    *) echo '*' ;;
  esac
}

files="$(changed_files | grep -v '^$' || true)"
docs_only=true; runtime=false
if [ -z "$files" ]; then docs_only=false; fi
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if [ "$f" = '*' ]; then docs_only=false; runtime=true; continue; fi
  if printf '%s' "$f" | grep -Eq "$docs"; then continue; fi
  docs_only=false
  case "$f" in
    .github/workflows/try.yml) ;;      # the try workflow itself is part of the try stack
    .github/*) continue ;;             # every other workflow or GitHub config never reaches the shop
  esac
  if printf '%s' "$f" | grep -Eq "$not_runtime"; then continue; fi
  runtime=true
done <<< "$files"

echo "changed: $(printf '%s' "$files" | grep -c . || true) file(s); docs_only=$docs_only runtime=$runtime"
{ echo "docs_only=$docs_only"; echo "runtime=$runtime"; } >> "${GITHUB_OUTPUT:-/dev/stdout}"
