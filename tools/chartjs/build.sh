#!/bin/sh
# Builds public/scripts/lib/chart.js: Chart.js reduced to the parts the game uses (tools/chartjs/entry.js),
# bundled and minified as one ES module. Needs Node.js (npx); installs into a throwaway directory.
#
#   sh tools/chartjs/build.sh [version]      (from the repository root; default version below)
set -eu
VERSION="${1:-4.6.1}"
cd "$(dirname "$0")/../.."
ROOT="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp tools/chartjs/entry.js "$TMP/entry.js"
cd "$TMP"
npm init -y >/dev/null
npm install --silent --no-audit --no-fund "chart.js@$VERSION" esbuild@0.25 >/dev/null
npx esbuild entry.js --bundle --minify --format=esm --target=es2020 --legal-comments=none \
    --banner:js="/*! Chart.js v$VERSION (line + bar subset) | https://www.chartjs.org | MIT License | built by tools/chartjs/build.sh */" \
    --outfile="$ROOT/public/scripts/lib/chart.js"
echo "built public/scripts/lib/chart.js (Chart.js $VERSION)"
