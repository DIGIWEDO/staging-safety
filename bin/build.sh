#!/usr/bin/env bash
# Bouwt dist/staging-safety.zip: het bestand dat aan een GitHub-release hangt.
set -euo pipefail

root="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$root"

version="$( grep -m1 "define( 'STAGING_SAFETY_VERSION'" staging-safety.php | sed -E "s/.*'([0-9.]+)'.*/\1/" )"
header="$( grep -m1 '^ \* Version:' staging-safety.php | sed -E 's/.*Version: *//' )"

if [ "$version" != "$header" ]; then
	echo "Versienummers lopen uiteen: header $header, constant $version" >&2
	exit 1
fi

rm -rf dist build
mkdir -p build/staging-safety dist

rsync -a \
	--exclude '.git' --exclude '.idea' --exclude 'tests' --exclude 'bin' \
	--exclude 'dist' --exclude 'build' --exclude '.DS_Store' --exclude 'INSTALL.md' \
	./ build/staging-safety/

( cd build && zip -qr "../dist/staging-safety.zip" staging-safety )
rm -rf build

echo "dist/staging-safety.zip klaar — versie $version"
