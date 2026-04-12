#!/bin/bash
# scrape_all.sh — compatibility wrapper for Event Sync pipeline
# Usage: bash scrape_all.sh [--quiet]

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="$(command -v php || echo php)"
LOG="$DIR/data/event_sync.log"
QUIET=0
[[ "$1" == "--quiet" ]] && QUIET=1

start=$(date +%s)
timestamp=$(date '+%Y-%m-%d %H:%M:%S')

log() {
    echo "$1"
    echo "$1" >> "$LOG"
}

mkdir -p "$DIR/data"
echo "" >> "$LOG"
log "========================================="
log "  Event Sync started: $timestamp"
log "========================================="

run() {
    local label="$1"; shift
    log ""
    log "--- $label ---"
    if [[ $QUIET -eq 1 ]]; then
        "$PHP" "$@" >> "$LOG" 2>&1
    else
        "$PHP" "$@" 2>&1 | tee -a "$LOG"
    fi
    local code=${PIPESTATUS[0]}
    if [[ $code -ne 0 ]]; then
        log "  WARNING: $label exited with code $code"
    fi
}

run "Sync canonical venues" "$DIR/scripts/sync_venues.php" --include-discovered
run "Event Sync (all sources)" "$DIR/scripts/sync_events.php" --adapter=all
run "Compute venue scores" "$DIR/scripts/compute_venue_scores.php"
run "Compute dark nights" "$DIR/scripts/compute_dark_nights.php" --days=60
run "Compute performer scores" "$DIR/compute_scores.php"
run "Import band profiles" "$DIR/import_bands.php"

end=$(date +%s)
elapsed=$((end - start))
mins=$((elapsed / 60))
secs=$((elapsed % 60))

log ""
log "========================================="
log "  Done in ${mins}m ${secs}s"
log "  Log: $LOG"
log "========================================="
