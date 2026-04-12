-- Panic Booking bootstrap schema (MySQL 8+)
-- Applied by lib/db_bootstrap.php migration runner

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  type VARCHAR(255) NOT NULL CHECK(type IN ('band','venue')),
  is_admin BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED UNIQUE NOT NULL,
  type VARCHAR(255) NOT NULL,
  data LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scraped_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_date VARCHAR(32) NOT NULL,
  venue_name VARCHAR(191) NOT NULL,
  venue_city VARCHAR(64) NOT NULL DEFAULT '',
  bands VARCHAR(512) NOT NULL DEFAULT '[]',
  age_restriction VARCHAR(64) DEFAULT '',
  price VARCHAR(64) DEFAULT '',
  doors_time VARCHAR(32) DEFAULT '',
  show_time VARCHAR(32) DEFAULT '',
  is_sold_out BIGINT UNSIGNED DEFAULT 0,
  is_ticketed BIGINT UNSIGNED DEFAULT 0,
  notes TEXT,
  raw_meta TEXT,
  source_url VARCHAR(1024) DEFAULT '',
  scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  source VARCHAR(64) NOT NULL DEFAULT 'foopee'
);
CREATE UNIQUE INDEX idx_scraped_events_uniq
  ON scraped_events(event_date, venue_name, bands);

CREATE TABLE IF NOT EXISTS band_memberships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  band_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(255) NOT NULL DEFAULT 'member' CHECK(role IN ('manager','member')),
  status VARCHAR(255) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','active','rejected')),
  invited_by BIGINT UNSIGNED,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(band_id) REFERENCES profiles(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE(band_id, user_id)
);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  data LONGTEXT NOT NULL,
  read_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS claim_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(255) NOT NULL CHECK(entity_type IN ('band','venue')),
  entity_user_id BIGINT UNSIGNED NOT NULL,
  claimant_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(255) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','canceled')),
  representative_name VARCHAR(255) NOT NULL DEFAULT '',
  representative_role VARCHAR(255) NOT NULL DEFAULT '',
  contact_email VARCHAR(255) NOT NULL DEFAULT '',
  contact_phone VARCHAR(255) NOT NULL DEFAULT '',
  website VARCHAR(255) NOT NULL DEFAULT '',
  evidence_links TEXT NOT NULL,
  supporting_info TEXT NOT NULL,
  dedupe_score BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dedupe_notes TEXT NOT NULL,
  duplicate_candidates LONGTEXT NOT NULL,
  review_notes TEXT NOT NULL,
  reviewed_by_user_id BIGINT UNSIGNED,
  reviewed_at DATETIME,
  approved_at DATETIME,
  rejected_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(entity_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(claimant_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_claim_requests_status_created ON claim_requests(status, created_at);
CREATE INDEX idx_claim_requests_claimant_status ON claim_requests(claimant_user_id, status, created_at);
CREATE INDEX idx_claim_requests_entity_status ON claim_requests(entity_type, entity_user_id, status);

CREATE TABLE IF NOT EXISTS claim_action_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claim_request_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED,
  action VARCHAR(255) NOT NULL,
  notes TEXT NOT NULL,
  metadata LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(claim_request_id) REFERENCES claim_requests(id) ON DELETE CASCADE,
  FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_claim_action_logs_claim_created ON claim_action_logs(claim_request_id, created_at);

CREATE TABLE IF NOT EXISTS entity_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(255) NOT NULL CHECK(entity_type IN ('band','venue')),
  source_user_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  link_type VARCHAR(255) NOT NULL DEFAULT 'claim_transfer',
  notes TEXT NOT NULL,
  created_by_user_id BIGINT UNSIGNED,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(entity_type, source_user_id, link_type),
  FOREIGN KEY(source_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_entity_links_target ON entity_links(target_user_id, created_at);

CREATE TABLE IF NOT EXISTS booking_interests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venue_name VARCHAR(191) NOT NULL,
  venue_city VARCHAR(255) NOT NULL DEFAULT 'S.F.',
  event_date VARCHAR(32) NOT NULL,
  requester_type VARCHAR(255) NOT NULL DEFAULT 'band',
  requester_name VARCHAR(255) NOT NULL,
  requester_email VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  band_profile_id BIGINT UNSIGNED,
  status VARCHAR(255) NOT NULL DEFAULT 'new' CHECK(status IN ('new','seen','responded')),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS opportunities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venue_user_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  event_date VARCHAR(32) NOT NULL,
  start_time VARCHAR(255),
  end_time VARCHAR(255),
  genre_tags VARCHAR(255) NOT NULL DEFAULT '',
  compensation_notes TEXT NOT NULL,
  constraints_notes TEXT NOT NULL,
  status VARCHAR(255) NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed','canceled')),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(venue_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_opportunities_venue_date ON opportunities(venue_user_id, event_date);
CREATE INDEX idx_opportunities_status_date ON opportunities(status, event_date);

CREATE TABLE IF NOT EXISTS booking_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  opportunity_id BIGINT UNSIGNED NOT NULL,
  venue_user_id BIGINT UNSIGNED NOT NULL,
  band_user_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(255) NOT NULL DEFAULT 'inquiry' CHECK(status IN ('inquiry','withdrawn','converted','closed')),
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
  FOREIGN KEY(venue_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(band_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_booking_requests_opp_created ON booking_requests(opportunity_id, created_at);
CREATE INDEX idx_booking_requests_venue_status ON booking_requests(venue_user_id, status);
CREATE INDEX idx_booking_requests_band_status ON booking_requests(band_user_id, status);

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  opportunity_id BIGINT UNSIGNED NOT NULL,
  booking_request_id BIGINT UNSIGNED,
  venue_user_id BIGINT UNSIGNED NOT NULL,
  band_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(255) NOT NULL DEFAULT 'inquiry' CHECK(status IN ('inquiry','hold','offer_sent','accepted','contracted','canceled','completed')),
  event_date VARCHAR(32) NOT NULL,
  start_time VARCHAR(255),
  end_time VARCHAR(255),
  genre_tags VARCHAR(255) NOT NULL DEFAULT '',
  compensation_notes TEXT NOT NULL,
  constraints_notes TEXT NOT NULL,
  offer_notes TEXT NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
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
CREATE INDEX idx_bookings_venue_status ON bookings(venue_user_id, status, event_date);
CREATE INDEX idx_bookings_band_status ON bookings(band_user_id, status, event_date);
CREATE INDEX idx_bookings_opp_status ON bookings(opportunity_id, status);

CREATE TABLE IF NOT EXISTS booking_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(255),
  to_status VARCHAR(255) NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY(changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_booking_status_history_booking_created ON booking_status_history(booking_id, created_at);

CREATE TABLE IF NOT EXISTS booking_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  author_user_id BIGINT UNSIGNED NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_booking_notes_booking_created ON booking_notes(booking_id, created_at);

CREATE TABLE IF NOT EXISTS show_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_user_id BIGINT UNSIGNED,
  band_name VARCHAR(255) NOT NULL,
  band_profile_id BIGINT UNSIGNED,
  event_date VARCHAR(32) NOT NULL,
  venue_name VARCHAR(191) NOT NULL,
  reported_attendance BIGINT UNSIGNED,
  bar_impact VARCHAR(255) DEFAULT '' CHECK(bar_impact IN ('','high','medium','low','none')),
  cover_collected BIGINT UNSIGNED DEFAULT 0,
  would_rebook BIGINT UNSIGNED DEFAULT 1,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS performer_scores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  band_name VARCHAR(255) NOT NULL UNIQUE,
  band_profile_id BIGINT UNSIGNED,
  draw_score DOUBLE DEFAULT 0,
  revenue_score DOUBLE DEFAULT 0,
  reliability_score DOUBLE DEFAULT 0,
  momentum_score DOUBLE DEFAULT 0,
  composite_score DOUBLE DEFAULT 0,
  avg_attendance BIGINT UNSIGNED DEFAULT 0,
  estimated_draw BIGINT UNSIGNED DEFAULT 0,
  shows_tracked BIGINT UNSIGNED DEFAULT 0,
  shows_last_30 BIGINT UNSIGNED DEFAULT 0,
  shows_last_90 BIGINT UNSIGNED DEFAULT 0,
  best_day VARCHAR(255) DEFAULT '',
  best_venue_tier VARCHAR(255) DEFAULT '',
  venue_tier_max BIGINT UNSIGNED DEFAULT 0,
  is_ticketed_ratio DOUBLE DEFAULT 0,
  sold_out_count BIGINT UNSIGNED DEFAULT 0,
  last_show_date VARCHAR(255) DEFAULT '',
  insight_draw VARCHAR(255) DEFAULT '',
  insight_revenue VARCHAR(255) DEFAULT '',
  insight_reliability VARCHAR(255) DEFAULT '',
  insight_momentum VARCHAR(255) DEFAULT '',
  last_computed DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venue_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  description TEXT NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME,
  status VARCHAR(255) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','canceled')),
  capacity BIGINT UNSIGNED,
  visibility VARCHAR(255) NOT NULL DEFAULT 'public' CHECK(visibility IN ('public','private','unlisted')),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(venue_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_events_venue_start ON events(venue_id, start_at);
CREATE INDEX idx_events_status_visibility ON events(status, visibility);

CREATE TABLE IF NOT EXISTS ticket_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  price_cents BIGINT UNSIGNED NOT NULL,
  quantity_available BIGINT UNSIGNED NOT NULL,
  quantity_sold BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sales_start DATETIME,
  sales_end DATETIME,
  max_per_order BIGINT UNSIGNED NOT NULL DEFAULT 10,
  is_active BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
);
CREATE INDEX idx_ticket_types_event ON ticket_types(event_id);
CREATE INDEX idx_ticket_types_active ON ticket_types(event_id, is_active);

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED,
  buyer_name VARCHAR(255) NOT NULL,
  buyer_email VARCHAR(255) NOT NULL,
  total_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency VARCHAR(255) NOT NULL DEFAULT 'USD',
  status VARCHAR(255) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed','refunded','canceled')),
  payment_provider VARCHAR(255),
  payment_reference VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_orders_event_status ON orders(event_id, status);
CREATE INDEX idx_orders_buyer_email ON orders(buyer_email);

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  quantity BIGINT UNSIGNED NOT NULL,
  unit_price_cents BIGINT UNSIGNED NOT NULL,
  line_total_cents BIGINT UNSIGNED NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT
);
CREATE INDEX idx_order_items_order ON order_items(order_id);
CREATE INDEX idx_order_items_ticket_type ON order_items(ticket_type_id);

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED,
  event_id BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED,
  attendee_name VARCHAR(255),
  attendee_email VARCHAR(255),
  qr_token VARCHAR(255) NOT NULL UNIQUE,
  short_code VARCHAR(255) NOT NULL UNIQUE,
  status VARCHAR(255) NOT NULL DEFAULT 'valid' CHECK(status IN ('valid','checked_in','voided','refunded')),
  checked_in_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY(ticket_type_id) REFERENCES ticket_types(id) ON DELETE RESTRICT,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_tickets_event_status ON tickets(event_id, status);
CREATE INDEX idx_tickets_order ON tickets(order_id);

CREATE TABLE IF NOT EXISTS checkins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED,
  event_id BIGINT UNSIGNED NOT NULL,
  checked_in_by_user_id BIGINT UNSIGNED NOT NULL,
  result_status VARCHAR(255) NOT NULL,
  note TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY(checked_in_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_checkins_event_created ON checkins(event_id, created_at);
CREATE INDEX idx_checkins_ticket ON checkins(ticket_id);

CREATE TABLE IF NOT EXISTS payment_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(255) NOT NULL,
  event_id VARCHAR(255) NOT NULL,
  event_type VARCHAR(255) NOT NULL,
  payload_hash VARCHAR(255) NOT NULL,
  status VARCHAR(255) NOT NULL DEFAULT 'received' CHECK(status IN ('received','processing','processed','ignored','error')),
  related_order_id BIGINT UNSIGNED,
  note TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME,
  UNIQUE(provider, event_id),
  FOREIGN KEY(related_order_id) REFERENCES orders(id) ON DELETE SET NULL
);
CREATE INDEX idx_payment_webhook_events_status ON payment_webhook_events(provider, status, created_at);
CREATE INDEX idx_payment_webhook_events_order ON payment_webhook_events(related_order_id);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME,
  request_ip VARCHAR(255) NOT NULL DEFAULT '',
  request_user_agent VARCHAR(1024) NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX idx_password_reset_tokens_user ON password_reset_tokens(user_id, created_at);
CREATE INDEX idx_password_reset_tokens_expiry ON password_reset_tokens(expires_at, used_at);
