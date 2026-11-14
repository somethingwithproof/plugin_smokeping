#!/bin/sh
# Regenerate lib/ProbeCatalog.php from the probe modules of every supported
# SmokePing release:
#
#   sh tools/make-probe-catalog.sh
#
# Runs tools/probe-catalog.pl in the oracle images tests/oracle/run.sh builds
# (2.7.3, 2.8.2, 2.9.0) without a network, then merges the results with
# tools/make-probe-catalog.php. Review the diff, and rerun tests/oracle/run.sh.
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

for spec in ubuntu:2.7.3 debian:2.8.2 fedora:2.9.0; do
	image=smokeping-oracle-${spec%%:*}:${spec#*:}
	docker image inspect "$image" >/dev/null 2>&1 || { echo "make-probe-catalog: $image is missing; run tests/oracle/run.sh first" >&2; exit 1; }
	docker run --rm --network none -v "$ROOT":/p:ro "$image" perl /p/tools/probe-catalog.pl > "$WORK/${spec#*:}.json"
done

docker run --rm -v "$ROOT":/p -v "$WORK":/w -w /p php:8.2-cli \
	php tools/make-probe-catalog.php lib/ProbeCatalog.php 2.7.3=/w/2.7.3.json 2.8.2=/w/2.8.2.json 2.9.0=/w/2.9.0.json
