-- Seeded profile claim workflow
-- Adds claim request lifecycle, audit logs, entity transfer links,
-- and profile archival metadata used during claim approval.

BEGIN;

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

ALTER TABLE profiles ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0;
ALTER TABLE profiles ADD COLUMN archived_at DATETIME;
ALTER TABLE profiles ADD COLUMN archived_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE profiles ADD COLUMN claimed_by_user_id INTEGER;
ALTER TABLE profiles ADD COLUMN claimed_at DATETIME;

CREATE INDEX IF NOT EXISTS idx_profiles_type_archived ON profiles(type, is_archived, is_generic, is_claimed);
CREATE INDEX IF NOT EXISTS idx_profiles_claimed_by ON profiles(claimed_by_user_id);

COMMIT;
