#!/usr/bin/env bash
# Builds the installable zip: .github/scripts/build-release.sh <tag>
# Stable tags (no "-") also produce latest.zip and version.txt, which every install auto-updates from.
set -euo pipefail

tag="${1:?usage: build-release.sh <tag>}"
if [[ ! "$tag" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "Invalid tag '$tag': expected X.Y.Z or X.Y.Z-prerelease (no v prefix)" >&2
    exit 1
fi
root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

test -f "$root/vendor/autoload.php" || { echo "vendor/ is missing; run composer install --no-dev first" >&2; exit 1; }

mkdir "$work/LJPcCalendarModule"
rsync -a --exclude-from="$root/.github/scripts/release-exclude.txt" "$root/" "$work/LJPcCalendarModule/"
rm -f "$root/$tag.zip"
(cd "$work" && zip -qr "$root/$tag.zip" LJPcCalendarModule)

if [[ "$tag" != *-* ]]; then
    cp "$root/$tag.zip" "$root/latest.zip"
    echo "$tag" > "$root/version.txt"
fi
echo "Built $tag.zip"
