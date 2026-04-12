#!/usr/bin/env bash
# cron_sync.sh — cron-safe Event Sync runner
# Usage:
#   bash cron_sync.sh [--quiet]

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

run_step "Sync canonical venues" "$DIR/scripts/sync_venues.php" --include-discovered
run_step "Event Sync (all adapters)" "$DIR/scripts/sync_events.php" --adapter=all --skip-venue-sync
run_step "Compute venue scores" "$DIR/scripts/compute_venue_scores.php"
run_step "Compute dark nights" "$DIR/scripts/compute_dark_nights.php" --days=60
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
