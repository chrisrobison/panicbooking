<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = panicConnectPdo();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Auto-create tables
$pdo->exec("
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
");

// Migration: add source column for multi-venue scraping
try {
    $pdo->exec("ALTER TABLE scraped_events ADD COLUMN source TEXT NOT NULL DEFAULT 'foopee'");
} catch (PDOException $e) {
    // Column already exists — safe to ignore
}

// Migration: add is_claimed and is_generic to profiles
try { $pdo->exec("ALTER TABLE profiles ADD COLUMN is_claimed INTEGER NOT NULL DEFAULT 0"); } catch(PDOException $e){}
try { $pdo->exec("ALTER TABLE profiles ADD COLUMN is_generic INTEGER NOT NULL DEFAULT 0"); } catch(PDOException $e){}
try { $pdo->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0"); } catch(PDOException $e){}

// Seed sample data (INSERT OR IGNORE with fixed IDs)
$demoPassword = password_hash('demo1234', PASSWORD_DEFAULT);

$pdo->exec("
INSERT OR IGNORE INTO users (id, email, password_hash, type) VALUES
  (1, 'fogcityramblers@example.com', '$demoPassword', 'band'),
  (2, 'staticdischarge@example.com', '$demoPassword', 'band'),
  (3, 'velvetundertow@example.com', '$demoPassword', 'band'),
  (4, 'rustynail@example.com', '$demoPassword', 'venue'),
  (5, 'missionbasement@example.com', '$demoPassword', 'venue'),
  (6, 'fillmoreghost@example.com', '$demoPassword', 'venue');
");

$band1 = json_encode([
    'name' => 'The Fog City Ramblers',
    'genres' => ['Classic Rock', 'Punk'],
    'members' => ['Jack Malone', 'Rita Chen', 'Dave Okonkwo', 'Simone Bauer'],
    'description' => 'SF veterans playing hard-hitting classic rock and punk since 2008. We bring the energy every single night and have played every dive bar from the Tenderloin to the Outer Sunset.',
    'contact_email' => 'fogcityramblers@example.com',
    'contact_phone' => '415-555-0101',
    'website' => 'https://fogcityramblers.com',
    'facebook' => 'https://facebook.com/fogcityramblers',
    'instagram' => 'https://instagram.com/fogcityramblers',
    'spotify' => '',
    'youtube' => '',
    'location' => 'San Francisco, CA',
    'experience' => 'Professional',
    'set_length_min' => 45,
    'set_length_max' => 90,
    'has_own_equipment' => true,
    'available_last_minute' => true,
    'notes' => 'We can do last-minute fills with as little as 24 hours notice. Full PA available.'
]);

$band2 = json_encode([
    'name' => 'Static Discharge',
    'genres' => ['Punk', 'Alternative'],
    'members' => ['Tessa Voss', 'Marco Reyes', 'Anya Petrov'],
    'description' => 'High energy 3-piece punk outfit from the Mission. Loud, fast, and we mean every word. Drawing from 80s hardcore and 90s alt-rock with a distinctly SF grit.',
    'contact_email' => 'staticdischarge@example.com',
    'contact_phone' => '415-555-0202',
    'website' => '',
    'facebook' => 'https://facebook.com/staticdischargeband',
    'instagram' => 'https://instagram.com/staticdischarge',
    'spotify' => 'https://open.spotify.com/artist/staticdischarge',
    'youtube' => '',
    'location' => 'San Francisco, CA',
    'experience' => 'Semi-Pro',
    'set_length_min' => 30,
    'set_length_max' => 60,
    'has_own_equipment' => false,
    'available_last_minute' => true,
    'notes' => 'We need a working PA. Backline helpful but not required.'
]);

$band3 = json_encode([
    'name' => 'Velvet Undertow',
    'genres' => ['Alternative', 'Indie'],
    'members' => ['Celeste Nakamura', 'Flynn O\'Brien', 'Nico Vasquez'],
    'description' => 'Moody atmospheric rock with layered guitars and haunting vocals. Influences range from Mazzy Star to Slowdive to early Afghan Whigs. Based in the Haight.',
    'contact_email' => 'velvetundertow@example.com',
    'contact_phone' => '415-555-0303',
    'website' => 'https://velvetundertow.bandcamp.com',
    'facebook' => '',
    'instagram' => 'https://instagram.com/velvetundertow',
    'spotify' => 'https://open.spotify.com/artist/velvetundertow',
    'youtube' => 'https://youtube.com/velvetundertow',
    'location' => 'San Francisco, CA',
    'experience' => 'Semi-Pro',
    'set_length_min' => 40,
    'set_length_max' => 75,
    'has_own_equipment' => false,
    'available_last_minute' => false,
    'notes' => 'Prefer at least 2 weeks notice. We have a specific sound setup — please inquire.'
]);

$venue1 = json_encode([
    'name' => 'The Rusty Nail',
    'address' => '742 Folsom St, San Francisco, CA 94107',
    'neighborhood' => 'SoMa',
    'capacity' => 150,
    'description' => 'A proper rock bar in the heart of SoMa. Sticky floors, cold beer, loud music — the way it should be. We book live music Thursday through Saturday and occasional Sundays.',
    'contact_email' => 'rustynail@example.com',
    'contact_phone' => '415-555-0401',
    'website' => 'https://therustynailsf.com',
    'facebook' => 'https://facebook.com/rustynailsf',
    'instagram' => 'https://instagram.com/rustynailsf',
    'genres_welcomed' => ['Classic Rock', 'Punk', 'Alternative', 'Rock'],
    'has_pa' => true,
    'has_drums' => true,
    'has_backline' => false,
    'stage_size' => 'Medium 10-20ft',
    'cover_charge' => true,
    'bar_service' => true,
    'open_to_last_minute' => true,
    'booking_lead_time_days' => 1,
    'notes' => 'We pay a flat door split after expenses. Must be 21+ show. Load-in at 7pm, doors at 8pm.'
]);

$venue2 = json_encode([
    'name' => 'Mission Basement',
    'address' => '3021 16th St, San Francisco, CA 94103',
    'neighborhood' => 'Mission',
    'capacity' => 80,
    'description' => 'DIY all-ages venue in the Mission. Run by musicians for musicians. We keep it affordable and community-driven. All genres welcome, though we skew toward experimental and DIY-friendly acts.',
    'contact_email' => 'missionbasement@example.com',
    'contact_phone' => '415-555-0502',
    'website' => '',
    'facebook' => 'https://facebook.com/missionbasementsf',
    'instagram' => 'https://instagram.com/missionbasement',
    'genres_welcomed' => ['Punk', 'Alternative', 'Indie', 'Other'],
    'has_pa' => true,
    'has_drums' => false,
    'has_backline' => false,
    'stage_size' => 'Small <10ft',
    'cover_charge' => false,
    'bar_service' => false,
    'open_to_last_minute' => true,
    'booking_lead_time_days' => 0,
    'notes' => 'All ages. Donation at the door (suggested $5-10). Touring bands get a place to crash.'
]);

$venue3 = json_encode([
    'name' => 'The Fillmore Ghost',
    'address' => '1805 Geary Blvd, San Francisco, CA 94115',
    'neighborhood' => 'Haight-Ashbury',
    'capacity' => 300,
    'description' => 'A grand old room steeped in SF rock history. Updated sound and lighting while preserving the legendary atmosphere. Full production available for the right acts.',
    'contact_email' => 'fillmoreghost@example.com',
    'contact_phone' => '415-555-0601',
    'website' => 'https://fillmoreghostsf.com',
    'facebook' => 'https://facebook.com/fillmoreghostsf',
    'instagram' => 'https://instagram.com/fillmoreghostsf',
    'genres_welcomed' => ['Rock', 'Classic Rock', 'Alternative', 'Indie', 'Blues', 'Jazz'],
    'has_pa' => true,
    'has_drums' => true,
    'has_backline' => true,
    'stage_size' => 'Large 20ft+',
    'cover_charge' => true,
    'bar_service' => true,
    'open_to_last_minute' => false,
    'booking_lead_time_days' => 14,
    'notes' => 'Minimum 2 weeks booking lead time. We offer artist hospitality. Ticketing through our box office.'
]);

$stmt = $pdo->prepare("INSERT OR IGNORE INTO profiles (id, user_id, type, data) VALUES (?, ?, ?, ?)");
$stmt->execute([1, 1, 'band', $band1]);
$stmt->execute([2, 2, 'band', $band2]);
$stmt->execute([3, 3, 'band', $band3]);
$stmt->execute([4, 4, 'venue', $venue1]);
$stmt->execute([5, 5, 'venue', $venue2]);
$stmt->execute([6, 6, 'venue', $venue3]);
