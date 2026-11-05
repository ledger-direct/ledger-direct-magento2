#!/usr/bin/env bash
# Checks that the ruleset GitHub enforces matches the file in this repository.
#
#   .github/rulesets/check.sh <owner/repo> <ruleset file>
#
# The ruleset is looked up by the file's "name". Every value in the file must be the same on GitHub,
# and GitHub must have the same rules; values GitHub adds on its own (defaults the file leaves out)
# are not compared. The bypass list is left out: GitHub shows it only to admins of the repository.
# Needs gh, logged in or with GH_TOKEN set; any read access to the repository will do.
set -euo pipefail

repo=${1:?usage: check.sh <owner/repo> <ruleset file>}
file=${2:?usage: check.sh <owner/repo> <ruleset file>}
name=$(jq -r .name "$file")

id=$(gh api "repos/$repo/rulesets?per_page=100" \
  --jq ".[] | select(.name == \"$name\" and .source == \"$repo\") | .id")
if [ -z "$id" ]; then
  echo "::error::$repo has no ruleset named \"$name\"; $file is not in force"
  exit 1
fi
live=$(gh api "repos/$repo/rulesets/$id")

# The $ in the jq programs below belongs to jq, not to the shell.
# shellcheck disable=SC2016
# Same order on both sides: rules by type, checks by name, merge methods alphabetically.
norm='def norm:
  del(.bypass_actors)
  | if .rules then .rules |= (sort_by(.type) | map(
      if .parameters.required_status_checks then .parameters.required_status_checks |= sort_by(.context) else . end
      | if .parameters.allowed_merge_methods then .parameters.allowed_merge_methods |= sort else . end))
    else . end;'
# shellcheck disable=SC2016
# Every value in $want is the same in $have; arrays have the same length.
subset='def subset($want; $have):
  if ($want | type) == "object" then
    ($have | type) == "object" and all($want | keys[]; . as $k | subset($want[$k]; $have[$k]))
  elif ($want | type) == "array" then
    ($have | type) == "array" and ($want | length) == ($have | length)
    and all(range(0; $want | length); . as $i | subset($want[$i]; $have[$i]))
  else $want == $have end;'
# shellcheck disable=SC2016
# $have cut down to the fields $want names, for a readable diff.
pick='def pick($want; $have):
  if ($want | type) == "object" and ($have | type) == "object" then
    reduce ($want | keys[]) as $k ({}; .[$k] = pick($want[$k]; $have[$k]))
  elif ($want | type) == "array" and ($have | type) == "array" and ($want | length) == ($have | length) then
    [range(0; $want | length) as $i | pick($want[$i]; $have[$i])]
  else $have end;'

if jq -n -e --slurpfile want "$file" --argjson have "$live" \
  "$norm $subset subset(\$want[0] | norm; \$have | norm)" > /dev/null; then
  echo "ruleset \"$name\" (id $id) on $repo matches $file"
  echo "not compared: the bypass list, which GitHub shows only to admins"
else
  echo "::error::ruleset \"$name\" (id $id) on $repo differs from $file"
  echo "< on GitHub, > in $file:"
  diff <(jq -n -S --slurpfile want "$file" --argjson have "$live" \
           "$norm $pick pick(\$want[0] | norm; \$have | norm)") \
       <(jq -S "$norm norm" "$file") || true
  exit 1
fi
