-- Panic Booking booking workflow schema (opportunities + lifecycle)
-- SQLite-first bootstrap SQL.
-- MySQL migration notes:
--   - Replace INTEGER PRIMARY KEY AUTOINCREMENT with BIGINT AUTO_INCREMENT PRIMARY KEY
--   - Replace INTEGER booleans with TINYINT(1)
--   - Keep CHECK constraints only if your MySQL version enforces them

CREATE TABLE IF NOT EXISTS opportunities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  venue_user_id INTEGER NOT NULL,
  created_by_user_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  event_date TEXT NOT NULL,
  start_time TEXT,
  end_time TEXT,
  genre_tags TEXT NOT NULL DEFAULT '',
  compensation_notes TEXT NOT NULL DEFAULT '',
  constraints_notes TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(venue_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_opportunities_venue_date ON opportunities(venue_user_id, event_date);
CREATE INDEX IF NOT EXISTS idx_opportunities_status_date ON opportunities(status, event_date);

CREATE TABLE IF NOT EXISTS booking_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  opportunity_id INTEGER NOT NULL,
  venue_user_id INTEGER NOT NULL,
  band_user_id INTEGER NOT NULL,
  message TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'inquiry',
  created_by_user_id INTEGER NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  FOREIGN KEY(venue_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(band_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_booking_requests_opp_created ON booking_requests(opportunity_id, created_at);
CREATE INDEX IF NOT EXISTS idx_booking_requests_venue_status ON booking_requests(venue_user_id, status);
CREATE INDEX IF NOT EXISTS idx_booking_requests_band_status ON booking_requests(band_user_id, status);

CREATE TABLE IF NOT EXISTS bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  opportunity_id INTEGER NOT NULL,
  booking_request_id INTEGER,
  venue_user_id INTEGER NOT NULL,
  band_user_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'inquiry',
  event_date TEXT NOT NULL,
  start_time TEXT,
  end_time TEXT,
  genre_tags TEXT NOT NULL DEFAULT '',
  compensation_notes TEXT NOT NULL DEFAULT '',
  constraints_notes TEXT NOT NULL DEFAULT '',
  offer_notes TEXT NOT NULL DEFAULT '',
  created_by_user_id INTEGER NOT NULL,
  accepted_at DATETIME,
  contracted_at DATETIME,
  canceled_at DATETIME,
  completed_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  FOREIGN KEY(booking_request_id) REFERENCES booking_requests(id) ON DELETE SET NULL,
  FOREIGN KEY(venue_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(band_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_bookings_venue_status ON bookings(venue_user_id, status, event_date);
CREATE INDEX IF NOT EXISTS idx_bookings_band_status ON bookings(band_user_id, status, event_date);
CREATE INDEX IF NOT EXISTS idx_bookings_opp_status ON bookings(opportunity_id, status);

CREATE TABLE IF NOT EXISTS booking_status_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_id INTEGER NOT NULL,
  from_status TEXT,
  to_status TEXT NOT NULL,
  changed_by_user_id INTEGER NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY(changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_booking_status_history_booking_created ON booking_status_history(booking_id, created_at);

CREATE TABLE IF NOT EXISTS booking_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  booking_id INTEGER NOT NULL,
  author_user_id INTEGER NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_booking_notes_booking_created ON booking_notes(booking_id, created_at);
