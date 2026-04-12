#!/usr/bin/env bash
# cron_sync.sh — cron-safe importer/scraper runner
# Usage:
#   bash cron_sync.sh [--quiet]
#
# Runs:
#   - scrape_foopee.php
#   - scrape_venues.php all
#   - scrape_venues.php ticketmaster (only if TM_API_KEY set or PB_ENABLE_TICKETMASTER=1)
#   - compute_scores.php
#   - import_bands.php
#   - import_venues.php

set -u
set -o pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
DATA_DIR="$DIR/data"
LOG_FILE="$DATA_DIR/cron_sync.log"
LOCK_DIR="$DATA_DIR/cron_sync.lock"

QUIET=0
if [[ "${1:-}" == "--quiet" ]]; then
    QUIET=1
fi

if [[ -z "$PHP_BIN" ]]; then
    echo "ERROR: php binary not found in PATH and PHP_BIN not set."
    exit 127
fi

mkdir -p "$DATA_DIR"

log() {
    local msg="$1"
    local ts
    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    local line="[$ts] $msg"
    echo "$line" >> "$LOG_FILE"
    if [[ $QUIET -eq 0 ]]; then
        echo "$line"
    fi
}

# Atomic lock to prevent overlapping cron runs.
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    log "Another cron_sync run is already in progress; exiting."
    exit 0
fi
trap 'rm -rf "$LOCK_DIR"' EXIT INT TERM

START_TS="$(date +%s)"
FAILURES=0

run_step() {
    local label="$1"
    shift

    log "--- $label ---"
    if [[ $QUIET -eq 1 ]]; then
        "$PHP_BIN" "$@" >> "$LOG_FILE" 2>&1
    else
        "$PHP_BIN" "$@" 2>&1 | tee -a "$LOG_FILE"
    fi

    local code=${PIPESTATUS[0]}
    if [[ $code -ne 0 ]]; then
        log "WARNING: $label failed with exit code $code"
        FAILURES=$((FAILURES + 1))
    else
        log "OK: $label"
    fi
}

log "========================================="
log "cron_sync started"
log "Working directory: $DIR"
log "PHP binary: $PHP_BIN"
log "========================================="

run_step "Scrape Foopee" "$DIR/scrape_foopee.php"
run_step "Scrape venues (all non-ticketmaster)" "$DIR/scrape_venues.php" all

if [[ -n "${TM_API_KEY:-}" || "${PB_ENABLE_TICKETMASTER:-0}" == "1" ]]; then
    run_step "Scrape venues (ticketmaster)" "$DIR/scrape_venues.php" ticketmaster
else
    log "Skipping Ticketmaster scrape (TM_API_KEY not set; set PB_ENABLE_TICKETMASTER=1 to force)."
fi

run_step "Compute performer scores" "$DIR/compute_scores.php"
run_step "Import bands" "$DIR/import_bands.php"
run_step "Import venues" "$DIR/import_venues.php"

END_TS="$(date +%s)"
ELAPSED=$((END_TS - START_TS))
MINS=$((ELAPSED / 60))
SECS=$((ELAPSED % 60))

log "========================================="
log "cron_sync finished in ${MINS}m ${SECS}s"
log "Failures: $FAILURES"
log "Log file: $LOG_FILE"
log "========================================="

if [[ $FAILURES -gt 0 ]]; then
    exit 1
fi
exit 0

