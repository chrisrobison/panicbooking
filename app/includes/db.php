<?php
// Database path: ../data/booking.db (relative to this file's directory)
// The web server needs write access to the data/ directory at the project root.

$dbPath = __DIR__ . '/../../data/booking.db';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    // Migration: add is_admin column if it doesn't exist yet
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
    } catch (PDOException $ignored) { /* already exists */ }
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
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
");

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
