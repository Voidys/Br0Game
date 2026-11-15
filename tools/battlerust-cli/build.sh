#!/bin/sh
# Builds bin/battlerust (the standalone launcher for bin/libbattlerust.so) without a local compiler:
# compiles battlerust-cli.c in a throwaway Ubuntu 20.04 container. A binary only runs on systems whose glibc is at
# least as new as the one it was built against: Ubuntu 20.04 (glibc 2.31) keeps the launcher usable on every
# distribution libbattlerust.so itself supports (glibc >= 2.30: Ubuntu 20.04+, Debian 11+, RHEL/Alma 9+).
#
#   sh tools/battlerust-cli/build.sh            (from the repository root)
set -eu
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD":/src -w /src ubuntu:20.04 sh -c 'export DEBIAN_FRONTEND=noninteractive;
    apt-get update -qq >/dev/null && apt-get install -y -qq --no-install-recommends gcc libc6-dev >/dev/null &&
    gcc -O2 -Wall -Wextra -o bin/battlerust tools/battlerust-cli/battlerust-cli.c -ldl &&
    chown '"$(id -u):$(id -g)"' bin/battlerust'
echo "built bin/battlerust"
