-- Panic Booking bootstrap schema (SQLite)
-- Applied by lib/db_bootstrap.php migration runner

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('band','venue')),
  is_admin INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER UNIQUE NOT NULL,
  type TEXT NOT NULL,
  data TEXT NOT NULL DEFAULT '{}',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scraped_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_date TEXT NOT NULL,
  venue_name TEXT NOT NULL,
  venue_city TEXT NOT NULL DEFAULT '',
  bands TEXT NOT NULL DEFAULT '[]',
  age_restriction TEXT DEFAULT '',
  price TEXT DEFAULT '',
  doors_time TEXT DEFAULT '',
  show_time TEXT DEFAULT '',
  is_sold_out INTEGER DEFAULT 0,
  is_ticketed INTEGER DEFAULT 0,
  notes TEXT DEFAULT '',
  raw_meta TEXT DEFAULT '',
  source_url TEXT DEFAULT '',
  scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  source TEXT NOT NULL DEFAULT 'foopee'
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_scraped_events_uniq
  ON scraped_events(event_date, venue_name, bands);

CREATE TABLE IF NOT EXISTS band_memberships (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  band_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  role TEXT NOT NULL DEFAULT 'member' CHECK(role IN ('manager','member')),
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','rejected')),
  invited_by INTEGER,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(band_id) REFERENCES profiles(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE(band_id, user_id)
);

CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  type TEXT NOT NULL,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  data TEXT NOT NULL DEFAULT '{}',
  read_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS claim_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL CHECK(entity_type IN ('band','venue')),
  entity_user_id INTEGER NOT NULL,
  claimant_user_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','canceled')),
  representative_name TEXT NOT NULL DEFAULT '',
  representative_role TEXT NOT NULL DEFAULT '',
  contact_email TEXT NOT NULL DEFAULT '',
  contact_phone TEXT NOT NULL DEFAULT '',
  website TEXT NOT NULL DEFAULT '',
  evidence_links TEXT NOT NULL DEFAULT '',
  supporting_info TEXT NOT NULL DEFAULT '',
  dedupe_score INTEGER NOT NULL DEFAULT 0,
  dedupe_notes TEXT NOT NULL DEFAULT '',
  duplicate_candidates TEXT NOT NULL DEFAULT '[]',
  review_notes TEXT NOT NULL DEFAULT '',
  reviewed_by_user_id INTEGER,
  reviewed_at DATETIME,
  approved_at DATETIME,
  rejected_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(entity_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(claimant_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_claim_requests_status_created ON claim_requests(status, created_at);
CREATE INDEX IF NOT EXISTS idx_claim_requests_claimant_status ON claim_requests(claimant_user_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_claim_requests_entity_status ON claim_requests(entity_type, entity_user_id, status);

CREATE TABLE IF NOT EXISTS claim_action_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  claim_request_id INTEGER NOT NULL,
  actor_user_id INTEGER,
  action TEXT NOT NULL,
  notes TEXT NOT NULL DEFAULT '',
  metadata TEXT NOT NULL DEFAULT '{}',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(claim_request_id) REFERENCES claim_requests(id) ON DELETE CASCADE,
  FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_claim_action_logs_claim_created ON claim_action_logs(claim_request_id, created_at);

CREATE TABLE IF NOT EXISTS entity_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL CHECK(entity_type IN ('band','venue')),
  source_user_id INTEGER NOT NULL,
  target_user_id INTEGER NOT NULL,
  link_type TEXT NOT NULL DEFAULT 'claim_transfer',
  notes TEXT NOT NULL DEFAULT '',
  created_by_user_id INTEGER,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(entity_type, source_user_id, link_type),
  FOREIGN KEY(source_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_entity_links_target ON entity_links(target_user_id, created_at);

CREATE TABLE IF NOT EXISTS booking_interests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  venue_name TEXT NOT NULL,
  venue_city TEXT NOT NULL DEFAULT 'S.F.',
  event_date TEXT NOT NULL,
  requester_type TEXT NOT NULL DEFAULT 'band',
  requester_name TEXT NOT NULL,
  requester_email TEXT NOT NULL,
  message TEXT NOT NULL DEFAULT '',
  band_profile_id INTEGER,
  status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','seen','responded')),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

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
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed','canceled')),
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
  status TEXT NOT NULL DEFAULT 'inquiry' CHECK(status IN ('inquiry','withdrawn','converted','closed')),
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
  status TEXT NOT NULL DEFAULT 'inquiry' CHECK(status IN ('inquiry','hold','offer_sent','accepted','contracted','canceled','completed')),
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

CREATE TABLE IF NOT EXISTS show_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reporter_user_id INTEGER,
  band_name TEXT NOT NULL,
  band_profile_id INTEGER,
  event_date TEXT NOT NULL,
  venue_name TEXT NOT NULL,
  reported_attendance INTEGER,
  bar_impact TEXT DEFAULT '' CHECK(bar_impact IN ('','high','medium','low','none')),
  cover_collected INTEGER DEFAULT 0,
  would_rebook INTEGER DEFAULT 1,
  notes TEXT DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS performer_scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  band_name TEXT NOT NULL UNIQUE,
  band_profile_id INTEGER,
  draw_score REAL DEFAULT 0,
  revenue_score REAL DEFAULT 0,
  reliability_score REAL DEFAULT 0,
  momentum_score REAL DEFAULT 0,
  composite_score REAL DEFAULT 0,
  avg_attendance INTEGER DEFAULT 0,
  estimated_draw INTEGER DEFAULT 0,
  shows_tracked INTEGER DEFAULT 0,
  shows_last_30 INTEGER DEFAULT 0,
  shows_last_90 INTEGER DEFAULT 0,
  best_day TEXT DEFAULT '',
  best_venue_tier TEXT DEFAULT '',
  venue_tier_max INTEGER DEFAULT 0,
  is_ticketed_ratio REAL DEFAULT 0,
  sold_out_count INTEGER DEFAULT 0,
  last_show_date TEXT DEFAULT '',
  insight_draw TEXT DEFAULT '',
  insight_revenue TEXT DEFAULT '',
  insight_reliability TEXT DEFAULT '',
  insight_momentum TEXT DEFAULT '',
  last_computed DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  venue_id INTEGER NOT NULL,
  created_by_user_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT NOT NULL DEFAULT '',
  start_at DATETIME NOT NULL,
  end_at DATETIME,
  status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','canceled')),
  capacity INTEGER,
  visibility TEXT NOT NULL DEFAULT 'public' CHECK(visibility IN ('public','private','unlisted')),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(venue_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_events_venue_start ON events(venue_id, start_at);
CREATE INDEX IF NOT EXISTS idx_events_status_visibility ON events(status, visibility);

CREATE TABLE IF NOT EXISTS ticket_types (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  description TEXT,
  price_cents INTEGER NOT NULL,
  quantity_available INTEGER NOT NULL,
  quantity_sold INTEGER NOT NULL DEFAULT 0,
  sales_start DATETIME,
  sales_end DATETIME,
  max_per_order INTEGER NOT NULL DEFAULT 10,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ticket_types_event ON ticket_types(event_id);
CREATE INDEX IF NOT EXISTS idx_ticket_types_active ON ticket_types(event_id, is_active);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  user_id INTEGER,
  buyer_name TEXT NOT NULL,
  buyer_email TEXT NOT NULL,
  total_cents INTEGER NOT NULL DEFAULT 0,
  currency TEXT NOT NULL DEFAULT 'USD',
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed','refunded','canceled')),
  payment_provider TEXT,
  payment_reference TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_event_status ON orders(event_id, status);
CREATE INDEX IF NOT EXISTS idx_orders_buyer_email ON orders(buyer_email);

CREATE TABLE IF NOT EXISTS order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  ticket_type_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL,
  unit_price_cents INTEGER NOT NULL,
  line_total_cents INTEGER NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_ticket_type ON order_items(ticket_type_id);

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  order_item_id INTEGER,
  event_id INTEGER NOT NULL,
  ticket_type_id INTEGER NOT NULL,
  user_id INTEGER,
  attendee_name TEXT,
  attendee_email TEXT,
  qr_token TEXT NOT NULL UNIQUE,
  short_code TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'valid' CHECK(status IN ('valid','checked_in','voided','refunded')),
  checked_in_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY(ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_tickets_event_status ON tickets(event_id, status);
CREATE INDEX IF NOT EXISTS idx_tickets_order ON tickets(order_id);

CREATE TABLE IF NOT EXISTS checkins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER,
  event_id INTEGER NOT NULL,
  checked_in_by_user_id INTEGER NOT NULL,
  result_status TEXT NOT NULL,
  note TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY(checked_in_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_checkins_event_created ON checkins(event_id, created_at);
CREATE INDEX IF NOT EXISTS idx_checkins_ticket ON checkins(ticket_id);

CREATE TABLE IF NOT EXISTS payment_webhook_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider TEXT NOT NULL,
  event_id TEXT NOT NULL,
  event_type TEXT NOT NULL,
  payload_hash TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'received' CHECK(status IN ('received','processing','processed','ignored','error')),
  related_order_id INTEGER,
  note TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME,
  UNIQUE(provider, event_id),
  FOREIGN KEY(related_order_id) REFERENCES orders(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_payment_webhook_events_status ON payment_webhook_events(provider, status, created_at);
CREATE INDEX IF NOT EXISTS idx_payment_webhook_events_order ON payment_webhook_events(related_order_id);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME,
  request_ip TEXT NOT NULL DEFAULT '',
  request_user_agent TEXT NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user ON password_reset_tokens(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_expiry ON password_reset_tokens(expires_at, used_at);
