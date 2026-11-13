#!/usr/bin/env bash
# Builds dist/self-captcha-standalone-<version>.zip with only what ships (see .distignore).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="self-captcha-standalone"
version="$(sed -n 's/^ \* Version: //p' "$root/$slug.php")"
work="$(mktemp -d)"

mkdir -p "$work/$slug" "$root/dist"
rsync -a --exclude-from="$root/.distignore" "$root/" "$work/$slug/"
rm -f "$root/dist/$slug-$version.zip"
(cd "$work" && zip -qr "$root/dist/$slug-$version.zip" "$slug")
rm -rf "$work"

echo "dist/$slug-$version.zip"
