#!/usr/bin/env bash
#
# The technical submission for the Adobe Commerce Marketplace: a zip of the module at a tag, with
# `version` written into composer.json. The repository's composer.json carries no version on
# purpose — Packagist takes it from the git tag — but the Marketplace's technical review wants
# the field in the submitted package.
#
#   bin/marketplace-zip.sh 1.1.3        → build/Hardcastle_LedgerDirect-1.1.3.zip
set -euo pipefail

tag=${1:?usage: bin/marketplace-zip.sh <tag>}
root=$(cd "$(dirname "$0")/.." && pwd)
build="$root/build"
name="Hardcastle_LedgerDirect-$tag"

git -C "$root" rev-parse -q --verify "refs/tags/$tag" >/dev/null || { echo "no tag $tag" >&2; exit 1; }

rm -rf "$build/$name" && mkdir -p "$build/$name"
# git archive applies .gitattributes export-ignore: tests, CI and tooling stay out, as for Packagist.
# --worktree-attributes: the export-ignore rules of the checkout apply, also to a tag made before they existed.
git -C "$root" archive --format=tar --worktree-attributes "$tag" | tar -x -C "$build/$name"

php -r '
  $file = $argv[1]; $version = $argv[2];
  $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
  foreach (["name", "type", "license", "autoload", "require"] as $key) {
    if (!isset($json[$key])) { fwrite(STDERR, "composer.json lacks $key\n"); exit(1); }
  }
  if ($json["type"] !== "magento2-module") { fwrite(STDERR, "type must be magento2-module\n"); exit(1); }
  foreach ($json["require"] as $package => $constraint) {
    if (str_starts_with($package, "magento/") && $constraint === "*") { fwrite(STDERR, "wildcard constraint on $package\n"); exit(1); }
  }
  $ordered = ["name" => $json["name"], "version" => $version] + $json;
  file_put_contents($file, json_encode($ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$build/$name/composer.json" "$tag"

grep -q "setup_version=\"$tag\"" "$build/$name/etc/module.xml" || { echo "etc/module.xml setup_version is not $tag" >&2; exit 1; }
test -f "$build/$name/registration.php" || { echo "registration.php missing" >&2; exit 1; }

( cd "$build/$name" && rm -f "../$name.zip" && zip -qr "../$name.zip" . -x '.git*' )
rm -rf "$build/$name"
ls -la "$build/$name.zip"
unzip -l "$build/$name.zip" | tail -1
