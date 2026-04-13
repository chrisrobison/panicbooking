-- Migration: Promoter & Agent roles (SQLite version)
-- Applied: 2026-04-12
-- -----------------------------------------------------------------------
-- Note: SQLite does not support DROP CONSTRAINT or ALTER COLUMN CHECK.
-- The users.type check is enforced at the application layer (PHP).
-- New roles 'promoter' and 'agent' are validated in auth handlers.
-- -----------------------------------------------------------------------

-- 1. Extend events
ALTER TABLE events ADD COLUMN doors_at              TEXT NULL;
ALTER TABLE events ADD COLUMN event_type            TEXT NOT NULL DEFAULT 'ticketed';
ALTER TABLE events ADD COLUMN promoted_by_user_id   INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;

-- 2. Event lineup
CREATE TABLE IF NOT EXISTS event_lineup (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id      INTEGER NOT NULL  REFERENCES events   (id) ON DELETE CASCADE,
    profile_id    INTEGER NULL      REFERENCES profiles (id) ON DELETE SET NULL,
    external_name TEXT    NULL,
    billing       TEXT    NOT NULL DEFAULT 'support'
                  CHECK (billing IN ('headliner','direct_support','support','opener','special_guest')),
    set_start     TEXT    NULL,
    set_end       TEXT    NULL,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- 3. Venue ↔ Promoter delegations
CREATE TABLE IF NOT EXISTS venue_promoter_delegations (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    venue_user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    promoter_user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status               TEXT    NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending','active','revoked')),
    granted_by_user_id   INTEGER NULL     REFERENCES users(id) ON DELETE SET NULL,
    note                 TEXT    NULL,
    created_at           TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at           TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (venue_user_id, promoter_user_id)
);

-- 4. Artist representations
CREATE TABLE IF NOT EXISTS artist_representations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_user_id   INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    band_profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
    status          TEXT    NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','active','inactive')),
    note            TEXT    NULL,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (agent_user_id, band_profile_id)
);
