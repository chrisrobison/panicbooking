CREATE TABLE IF NOT EXISTS event_sync_venues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(128) NOT NULL UNIQUE,
  name_key VARCHAR(191) NOT NULL UNIQUE,
  display_name VARCHAR(191) NOT NULL,
  aliases_json LONGTEXT NOT NULL,
  city VARCHAR(128) NOT NULL DEFAULT 'San Francisco',
  state VARCHAR(16) NOT NULL DEFAULT 'CA',
  venue_type VARCHAR(64) NOT NULL DEFAULT 'club',
  capacity_estimate BIGINT UNSIGNED,
  prestige_weight DOUBLE NOT NULL DEFAULT 0.5,
  activity_weight DOUBLE NOT NULL DEFAULT 0.7,
  source_priority_default INT NOT NULL DEFAULT 60,
  sync_enabled BIGINT UNSIGNED NOT NULL DEFAULT 1,
  official_calendar_url VARCHAR(1024) NOT NULL DEFAULT '',
  adapter_class VARCHAR(255) NOT NULL DEFAULT '',
  is_core_venue BIGINT UNSIGNED NOT NULL DEFAULT 0,
  has_official_sync BIGINT UNSIGNED NOT NULL DEFAULT 0,
  notoriety_multiplier DOUBLE NOT NULL DEFAULT 1.0,
  venue_score DOUBLE NOT NULL DEFAULT 0,
  venue_tier VARCHAR(32) NOT NULL DEFAULT 'Tier 4',
  last_scored_at DATETIME,
  last_synced_at DATETIME,
  last_sync_status VARCHAR(32) NOT NULL DEFAULT 'never',
  last_sync_error TEXT,
  coverage_confidence DOUBLE NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_event_sync_venues_sync ON event_sync_venues(sync_enabled, last_synced_at);
CREATE INDEX idx_event_sync_venues_tier ON event_sync_venues(venue_tier, venue_score);

CREATE TABLE IF NOT EXISTS event_ingestion_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venue_sync_id BIGINT UNSIGNED,
  venue_slug VARCHAR(128) NOT NULL,
  venue_name VARCHAR(191) NOT NULL,
  merge_key VARCHAR(255) NOT NULL,
  ingest_fingerprint VARCHAR(191) NOT NULL UNIQUE,
  source VARCHAR(64) NOT NULL,
  source_url VARCHAR(1024) NOT NULL DEFAULT '',
  source_event_id VARCHAR(191) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) NOT NULL DEFAULT '',
  start_datetime DATETIME NOT NULL,
  doors_datetime DATETIME,
  age_restriction VARCHAR(64) NOT NULL DEFAULT '',
  ticket_url VARCHAR(1024) NOT NULL DEFAULT '',
  status VARCHAR(64) NOT NULL DEFAULT '',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  source_priority INT NOT NULL DEFAULT 60,
  raw_payload LONGTEXT,
  normalized_title VARCHAR(255) NOT NULL DEFAULT '',
  raw_meta TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(venue_sync_id) REFERENCES event_sync_venues(id) ON DELETE SET NULL
);
CREATE INDEX idx_event_ingestion_merge ON event_ingestion_events(merge_key);
CREATE INDEX idx_event_ingestion_venue_seen ON event_ingestion_events(venue_slug, last_seen_at);
CREATE INDEX idx_event_ingestion_source_event ON event_ingestion_events(source, source_event_id);

CREATE TABLE IF NOT EXISTS venue_dark_nights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venue_sync_id BIGINT UNSIGNED,
  venue_slug VARCHAR(128) NOT NULL,
  dark_date DATE NOT NULL,
  confidence_level VARCHAR(16) NOT NULL DEFAULT 'low',
  confidence_score DOUBLE NOT NULL DEFAULT 0,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  is_likely_open BIGINT UNSIGNED NOT NULL DEFAULT 1,
  computed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_venue_dark_night (venue_slug, dark_date),
  FOREIGN KEY(venue_sync_id) REFERENCES event_sync_venues(id) ON DELETE SET NULL
);
CREATE INDEX idx_venue_dark_nights_date ON venue_dark_nights(dark_date, confidence_level);

ALTER TABLE scraped_events ADD COLUMN canonical_event_key VARCHAR(191);
ALTER TABLE scraped_events ADD COLUMN canonical_venue_slug VARCHAR(128);
ALTER TABLE scraped_events ADD COLUMN source_event_id VARCHAR(191) NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN subtitle VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN start_datetime DATETIME;
ALTER TABLE scraped_events ADD COLUMN doors_datetime DATETIME;
ALTER TABLE scraped_events ADD COLUMN ticket_url VARCHAR(1024) NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN status VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN first_seen_at DATETIME;
ALTER TABLE scraped_events ADD COLUMN last_seen_at DATETIME;
ALTER TABLE scraped_events ADD COLUMN source_priority INT NOT NULL DEFAULT 60;
ALTER TABLE scraped_events ADD COLUMN raw_payload LONGTEXT;
ALTER TABLE scraped_events ADD COLUMN normalized_title VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN last_merged_at DATETIME;

CREATE UNIQUE INDEX idx_scraped_events_canonical_key ON scraped_events(canonical_event_key);
CREATE INDEX idx_scraped_events_canonical_venue_date ON scraped_events(canonical_venue_slug, event_date);
CREATE INDEX idx_scraped_events_source_priority ON scraped_events(source, source_priority);
