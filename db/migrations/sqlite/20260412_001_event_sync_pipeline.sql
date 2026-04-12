CREATE TABLE IF NOT EXISTS event_sync_venues (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name_key TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  aliases_json TEXT NOT NULL DEFAULT '[]',
  city TEXT NOT NULL DEFAULT 'San Francisco',
  state TEXT NOT NULL DEFAULT 'CA',
  venue_type TEXT NOT NULL DEFAULT 'club',
  capacity_estimate INTEGER,
  prestige_weight REAL NOT NULL DEFAULT 0.5,
  activity_weight REAL NOT NULL DEFAULT 0.7,
  source_priority_default INTEGER NOT NULL DEFAULT 60,
  sync_enabled INTEGER NOT NULL DEFAULT 1,
  official_calendar_url TEXT NOT NULL DEFAULT '',
  adapter_class TEXT NOT NULL DEFAULT '',
  is_core_venue INTEGER NOT NULL DEFAULT 0,
  has_official_sync INTEGER NOT NULL DEFAULT 0,
  notoriety_multiplier REAL NOT NULL DEFAULT 1.0,
  venue_score REAL NOT NULL DEFAULT 0,
  venue_tier TEXT NOT NULL DEFAULT 'Tier 4',
  last_scored_at DATETIME,
  last_synced_at DATETIME,
  last_sync_status TEXT NOT NULL DEFAULT 'never',
  last_sync_error TEXT,
  coverage_confidence REAL NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_event_sync_venues_sync ON event_sync_venues(sync_enabled, last_synced_at);
CREATE INDEX IF NOT EXISTS idx_event_sync_venues_tier ON event_sync_venues(venue_tier, venue_score);

CREATE TABLE IF NOT EXISTS event_ingestion_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  venue_sync_id INTEGER,
  venue_slug TEXT NOT NULL,
  venue_name TEXT NOT NULL,
  merge_key TEXT NOT NULL,
  ingest_fingerprint TEXT NOT NULL UNIQUE,
  source TEXT NOT NULL,
  source_url TEXT NOT NULL DEFAULT '',
  source_event_id TEXT NOT NULL DEFAULT '',
  title TEXT NOT NULL,
  subtitle TEXT NOT NULL DEFAULT '',
  start_datetime DATETIME NOT NULL,
  doors_datetime DATETIME,
  age_restriction TEXT NOT NULL DEFAULT '',
  ticket_url TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT '',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  source_priority INTEGER NOT NULL DEFAULT 60,
  raw_payload TEXT,
  normalized_title TEXT NOT NULL DEFAULT '',
  raw_meta TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(venue_sync_id) REFERENCES event_sync_venues(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_event_ingestion_merge ON event_ingestion_events(merge_key);
CREATE INDEX IF NOT EXISTS idx_event_ingestion_venue_seen ON event_ingestion_events(venue_slug, last_seen_at);
CREATE INDEX IF NOT EXISTS idx_event_ingestion_source_event ON event_ingestion_events(source, source_event_id);

CREATE TABLE IF NOT EXISTS venue_dark_nights (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  venue_sync_id INTEGER,
  venue_slug TEXT NOT NULL,
  dark_date TEXT NOT NULL,
  confidence_level TEXT NOT NULL DEFAULT 'low',
  confidence_score REAL NOT NULL DEFAULT 0,
  reason TEXT NOT NULL DEFAULT '',
  is_likely_open INTEGER NOT NULL DEFAULT 1,
  computed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(venue_slug, dark_date),
  FOREIGN KEY(venue_sync_id) REFERENCES event_sync_venues(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_venue_dark_nights_date ON venue_dark_nights(dark_date, confidence_level);

ALTER TABLE scraped_events ADD COLUMN canonical_event_key TEXT;
ALTER TABLE scraped_events ADD COLUMN canonical_venue_slug TEXT;
ALTER TABLE scraped_events ADD COLUMN source_event_id TEXT NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN title TEXT NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN subtitle TEXT NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN start_datetime DATETIME;
ALTER TABLE scraped_events ADD COLUMN doors_datetime DATETIME;
ALTER TABLE scraped_events ADD COLUMN ticket_url TEXT NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN status TEXT NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN first_seen_at DATETIME;
ALTER TABLE scraped_events ADD COLUMN last_seen_at DATETIME;
ALTER TABLE scraped_events ADD COLUMN source_priority INTEGER NOT NULL DEFAULT 60;
ALTER TABLE scraped_events ADD COLUMN raw_payload TEXT;
ALTER TABLE scraped_events ADD COLUMN normalized_title TEXT NOT NULL DEFAULT '';
ALTER TABLE scraped_events ADD COLUMN last_merged_at DATETIME;

CREATE UNIQUE INDEX IF NOT EXISTS idx_scraped_events_canonical_key ON scraped_events(canonical_event_key);
CREATE INDEX IF NOT EXISTS idx_scraped_events_canonical_venue_date ON scraped_events(canonical_venue_slug, event_date);
CREATE INDEX IF NOT EXISTS idx_scraped_events_source_priority ON scraped_events(source, source_priority);
