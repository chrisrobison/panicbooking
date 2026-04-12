#!/bin/bash
# scrape_all.sh — fetch all show data and recompute scores
# Usage: bash scrape_all.sh [--quiet]

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="$(command -v php || echo php)"
LOG="$DIR/data/scrape.log"
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
log "  Scrape started: $timestamp"
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

run "The List (foopee.com)"      "$DIR/scrape_foopee.php"
run "GAMH"                        "$DIR/scrape_venues.php" gamh
run "The Warfield"                "$DIR/scrape_venues.php" warfield
run "Regency Ballroom"            "$DIR/scrape_venues.php" regency
run "The Fillmore (web)"          "$DIR/scrape_venues.php" fillmore
run "Ticketmaster (Fillmore / Bill Graham / Warfield)" "$DIR/scrape_venues.php" ticketmaster
run "Compute performer scores"   "$DIR/compute_scores.php"
run "Import band profiles"       "$DIR/import_bands.php"

end=$(date +%s)
elapsed=$((end - start))
mins=$((elapsed / 60))
secs=$((elapsed % 60))

log ""
log "========================================="
log "  Done in ${mins}m ${secs}s"
log "  Log: $LOG"
log "========================================="
