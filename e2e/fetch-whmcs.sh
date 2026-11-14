#!/usr/bin/env bash
# Downloads the official WHMCS package (public download API), verifies its SHA-256 and extracts it
# to e2e/.cache/whmcs-<version>/. Prints the version.
#
#   WHMCS_CHANNEL=stable (default) | lts       WHMCS_VERSION=<x.y.z> to require a specific version
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p .cache/dl
meta=$(curl -fsS "https://api1.whmcs.com/download/latest?type=${WHMCS_CHANNEL:-stable}")
field() { echo "$meta" | python3 -I -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"; }
version=$(field version)
url=$(field url)
sha=$(field sha256Checksum)
if [ -n "${WHMCS_VERSION:-}" ] && [ "$WHMCS_VERSION" != "$version" ]; then
  echo "WHMCS_VERSION=$WHMCS_VERSION requested, but the download API offers $version" >&2
  [ -d ".cache/whmcs-$WHMCS_VERSION" ] && { echo "$WHMCS_VERSION"; exit 0; }
  exit 1
fi
zip=".cache/dl/whmcs-$version.zip"
if [ ! -f "$zip" ] || ! echo "$sha  $zip" | sha256sum -c --quiet - 2>/dev/null; then
  echo "Downloading WHMCS $version" >&2
  curl -fsSL -o "$zip" "$url"
fi
echo "$sha  $zip" | sha256sum -c --quiet - || { echo "SHA-256 mismatch for $zip" >&2; rm -f "$zip"; exit 1; }
if [ ! -f ".cache/whmcs-$version/init.php" ]; then
  rm -rf ".cache/whmcs-$version" && mkdir -p ".cache/whmcs-$version"
  unzip -q "$zip" -d ".cache/whmcs-$version"
fi
echo "$version"
