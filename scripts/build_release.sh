#!/bin/sh
# Build the release archive of the Plesk extension: an explicit list of what
# ships, nothing else (no caches), then SHA-256.
set -eu
cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' ext/meta.xml)
out="${OUT:-dist}"
mkdir -p "$out"
name="email-spam-tester-plesk-$version.zip"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/pkg"
cp ext/meta.xml ext/DESCRIPTION.md ext/CHANGES.md INSTALL.md "$tmp/pkg/"
cp -r ext/htdocs ext/plib ext/sbin ext/_meta "$tmp/pkg/"
find "$tmp/pkg" \( -name '__pycache__' -o -name '*.pyc' -o -name '*.swp' -o -name '.DS_Store' \) -prune -exec rm -rf {} +
(cd "$tmp/pkg" && zip -qr -X "$tmp/$name" .)
mv "$tmp/$name" "$out/$name"
# latest.zip is what INSTALL.md downloads; its checksum file names latest.zip.
cp "$out/$name" "$out/latest.zip"
(cd "$out" && sha256sum "$name" > "$name.sha256" && sha256sum latest.zip > latest.zip.sha256)
echo "$out/$name"
cat "$out/$name.sha256"
