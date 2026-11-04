#!/usr/bin/env bash
# Writes docs_only=true|false and runtime=true|false to $GITHUB_OUTPUT (or stdout when unset).
# Reads the changed files from git, or from CHANGED_FILES and DELETED_FILES (newline-separated)
# for tests.
set -euo pipefail

docs='^([^/]+/)*[^/]+\.md$|^docs/|^readme\.txt$|^CHANGELOG|^LICENSE|^\.coderabbit\.yaml$|^\.github/(ISSUE_TEMPLATE/|PULL_REQUEST_TEMPLATE|CODEOWNERS|dependabot\.yml)'
# Changes that never reach the shop: tests and the configs of the test tools, CI workflows other than
# the try stack's, editor and git housekeeping.
not_runtime='^tests?/|^phpunit(\.xml)?(\.dist)?$|^phpstan[^/]*$|^\.phpcs[^/]*$|^phpcs\.xml(\.dist)?$|^\.php-cs-fixer[^/]*$|^\.editorconfig$|^\.gitattributes$|^\.gitignore$'

# The commits to diff: "<base> <head>", or empty when there is no base (then everything counts).
diff_range() {
  case "${EVENT-}" in
    pull_request)
      # actions/checkout checks out the merge commit; its first parent is the base branch.
      echo 'HEAD^1 HEAD' ;;
    push)
      # A version tag always counts as runtime, even when it was moved onto a docs-only commit:
      # the try workflow publishes the image for it.
      case "${GITHUB_REF-}" in
        refs/tags/*) echo "::notice::tag push; classifying as runtime" >&2; return ;;
      esac
      if [ -z "${BEFORE-}" ] || [ "$BEFORE" = "0000000000000000000000000000000000000000" ] \
         || ! git cat-file -e "$BEFORE^{commit}" 2>/dev/null; then
        echo "::notice::no base to diff against; classifying as runtime" >&2; return
      fi
      echo "$BEFORE $AFTER" ;;
    *) ;;
  esac
}

if [ -n "${CHANGED_FILES-}" ]; then
  files="$CHANGED_FILES"; deleted="${DELETED_FILES-}"
else
  range="$(diff_range)"
  if [ -n "$range" ]; then
    # shellcheck disable=SC2086 # two commits, split on the space
    # --no-renames lists a renamed file under both paths, so a runtime file moved into docs/
    # still counts as a runtime change (--name-only alone would show only the new path).
    files="$(git diff --no-renames --name-only $range)"
    # shellcheck disable=SC2086
    deleted="$(git diff --no-renames --name-only --diff-filter=D $range)"
  else
    files='*'; deleted=''
  fi
fi
files="$(printf '%s\n' "$files" | grep -v '^$' || true)"
deleted="$(printf '%s\n' "$deleted" | grep -v '^$' || true)"

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
# A deleted file is never documentation-only: a release build or a link elsewhere may require it
# (the release archive check needs LICENSE.md, for example). The full CI then runs.
if [ -n "$deleted" ]; then docs_only=false; fi

echo "changed: $(printf '%s' "$files" | grep -c . || true) file(s), deleted: $(printf '%s' "$deleted" | grep -c . || true); docs_only=$docs_only runtime=$runtime"
{ echo "docs_only=$docs_only"; echo "runtime=$runtime"; } >> "${GITHUB_OUTPUT:-/dev/stdout}"
