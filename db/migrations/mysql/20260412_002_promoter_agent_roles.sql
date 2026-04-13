-- Migration: Promoter & Agent roles, event lineup, delegation tables
-- Applied: 2026-04-12
-- -----------------------------------------------------------------------

-- -----------------------------------------------------------------------
-- 1. Extend users.type to include 'promoter' and 'agent'.
--
--    The bootstrap migration created an inline CHECK on users.type which
--    MySQL auto-named (typically 'users_chk_1'). MODIFY COLUMN drops
--    inline column-level checks in MySQL 8.0.16+; we then add an
--    explicitly-named table-level check with the full set of valid values.
--
--    If you get "Unknown CONSTRAINT" run:
--      SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND CONSTRAINT_TYPE='CHECK';
--    then adjust the name below.
-- -----------------------------------------------------------------------
ALTER TABLE users MODIFY COLUMN type VARCHAR(255) NOT NULL;
ALTER TABLE users ADD CONSTRAINT chk_users_type
    CHECK (type IN ('band', 'venue', 'promoter', 'agent'));

-- -----------------------------------------------------------------------
-- 2. Extend events with doors_at, event_type, and promoted_by_user_id.
--    event_type: 'ticketed' = full ticketed event (existing behaviour)
--                'listing'  = show announcement without ticketing
--                            (created by bands/agents; draft until venue confirms)
-- -----------------------------------------------------------------------
ALTER TABLE events
    ADD COLUMN doors_at              DATETIME         NULL            AFTER start_at,
    ADD COLUMN event_type            VARCHAR(255)     NOT NULL DEFAULT 'ticketed' AFTER visibility,
    ADD COLUMN promoted_by_user_id   BIGINT UNSIGNED  NULL            AFTER created_by_user_id;

ALTER TABLE events
    ADD CONSTRAINT chk_events_event_type
        CHECK (event_type IN ('ticketed', 'listing')),
    ADD CONSTRAINT fk_events_promoted_by
        FOREIGN KEY (promoted_by_user_id) REFERENCES users (id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------
-- 3. Event lineup: ordered acts for an event.
--    profile_id is NULL for acts not yet on the platform (use external_name).
-- -----------------------------------------------------------------------
CREATE TABLE event_lineup (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id      BIGINT UNSIGNED  NOT NULL,
    profile_id    BIGINT UNSIGNED  NULL,
    external_name VARCHAR(255)     NULL,
    billing       VARCHAR(255)     NOT NULL DEFAULT 'support',
    set_start     TIME             NULL,
    set_end       TIME             NULL,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_lineup_billing
        CHECK (billing IN ('headliner','direct_support','support','opener','special_guest')),
    CONSTRAINT fk_lineup_event
        FOREIGN KEY (event_id)   REFERENCES events   (id) ON DELETE CASCADE,
    CONSTRAINT fk_lineup_profile
        FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 4. Venue ↔ Promoter delegations.
--    A venue grants a promoter the right to create/manage events there.
--    status: pending (awaiting venue accept) | active | revoked
-- -----------------------------------------------------------------------
CREATE TABLE venue_promoter_delegations (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    venue_user_id        BIGINT UNSIGNED NOT NULL,
    promoter_user_id     BIGINT UNSIGNED NOT NULL,
    status               VARCHAR(255)    NOT NULL DEFAULT 'pending',
    granted_by_user_id   BIGINT UNSIGNED NULL,
    note                 TEXT            NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vpd_venue_promoter (venue_user_id, promoter_user_id),
    CONSTRAINT chk_vpd_status
        CHECK (status IN ('pending','active','revoked')),
    CONSTRAINT fk_vpd_venue
        FOREIGN KEY (venue_user_id)      REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_vpd_promoter
        FOREIGN KEY (promoter_user_id)   REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_vpd_granted_by
        FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------
-- 5. Artist representations: booking agents → bands they represent.
--    status: pending (awaiting band accept) | active | inactive
-- -----------------------------------------------------------------------
CREATE TABLE artist_representations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_user_id   BIGINT UNSIGNED NOT NULL,
    band_profile_id BIGINT UNSIGNED NOT NULL,
    status          VARCHAR(255)    NOT NULL DEFAULT 'pending',
    note            TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ar_agent_band (agent_user_id, band_profile_id),
    CONSTRAINT chk_ar_status
        CHECK (status IN ('pending','active','inactive')),
    CONSTRAINT fk_ar_agent
        FOREIGN KEY (agent_user_id)   REFERENCES users    (id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_band
        FOREIGN KEY (band_profile_id) REFERENCES profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
