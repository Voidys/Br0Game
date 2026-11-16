#!/bin/sh
# Builds, tests and checks the Rust battle engine in a pinned container, so nothing but Docker is needed on the host.
#
#   sh tools/battlerust/build.sh [build]   release build, copies the library to bin/libbattlerust.so, prints its sha256
#   sh tools/battlerust/build.sh test      cargo test (RNG vectors, engine units)
#   sh tools/battlerust/build.sh check     release build into a temp dir and compare its sha256 with bin/libbattlerust.so
#
# Run from anywhere; the repository root is found from the script location.
#
# Why this image: rust:<version>-bullseye is Debian 11 (glibc 2.31), so the library loads on every system the game
# supports for the Rust engine (glibc >= 2.30: Ubuntu 20.04+, Debian 11+, RHEL/Alma 9+). The toolchain version is pinned
# in IMAGE and the dependencies in Cargo.lock (--locked); strip, LTO and one codegen unit are set in Cargo.toml, the
# build paths are remapped and the build-id is dropped, so the same source gives the same bytes anywhere with this image.
set -eu

IMAGE="rust:1.91.1-bullseye"
MODE="${1:-build}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="$ROOT/bin/libbattlerust.so"

run() {
    docker run --rm \
        -v "$ROOT":/src \
        -v battlerust-cargo-registry:/cargo-home/registry \
        -w /src/tools/battlerust \
        -e CARGO_HOME=/cargo-home \
        -e CARGO_TARGET_DIR=/tmp/target \
        -e CARGO_INCREMENTAL=0 \
        -e SOURCE_DATE_EPOCH=0 \
        -e RUSTFLAGS="--remap-path-prefix=/src=/build --remap-path-prefix=/cargo-home=/cargo --remap-path-prefix=/usr/local/rustup=/rustup -C link-arg=-Wl,--build-id=none" \
        -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" \
        "$IMAGE" sh -c "$1"
}

case "$MODE" in
    test)
        run 'cargo test --locked --release --no-fail-fast -- --nocapture 2>&1; status=$?; chown -R "$HOST_UID:$HOST_GID" /src/tools/battlerust 2>/dev/null; exit $status'
        ;;
    build | check)
        TARGET="$OUT"
        [ "$MODE" = check ] && TARGET="$ROOT/tools/battlerust/target-check.so"
        run 'cargo build --locked --release &&
            cp /tmp/target/release/libbattlerust.so /src/tools/battlerust/.built.so &&
            chown "$HOST_UID:$HOST_GID" /src/tools/battlerust/.built.so /src/tools/battlerust/Cargo.lock'
        mv "$ROOT/tools/battlerust/.built.so" "$TARGET"
        chmod 755 "$TARGET"
        NEW="$(sha256sum "$TARGET" | cut -d' ' -f1)"
        if [ "$MODE" = check ]; then
            rm -f "$TARGET"
            OLD="$(sha256sum "$OUT" | cut -d' ' -f1)"
            echo "committed: $OLD"
            echo "fresh:     $NEW"
            [ "$OLD" = "$NEW" ] && echo "OK: bin/libbattlerust.so matches a fresh build" || { echo "MISMATCH: bin/libbattlerust.so differs from a fresh build"; exit 1; }
        else
            echo "built bin/libbattlerust.so"
            echo "sha256 $NEW"
        fi
        ;;
    *)
        echo "usage: $0 [build|test|check]" >&2
        exit 2
        ;;
esac
