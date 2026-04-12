# Panic Booking

Lean PHP app for San Francisco venue/band booking + ticketing MVP.

## Event Sync Pipeline

Panic Booking now supports an adapter-based Event Ingestion Pipeline that keeps
legacy `scraped_events` behavior intact while adding source-aware ingestion,
canonical venue identity, venue scoring, and dark-night computation.

### Core scripts

- `php scripts/sync_venues.php --include-discovered`
  - sync canonical venue definitions from `config/venues.php`
  - backfill discovered venue rows from existing `scraped_events`
- `php scripts/sync_events.php --adapter=all`
  - runs Event Sync adapters and merges rows into canonical `scraped_events`
  - supports `--venue=<slug>`, `--adapter=<key>`, `--dry-run`, `--verbose`
- `php scripts/compute_venue_scores.php`
  - computes deterministic venue scores and tiers (`Tier 1`..`Tier 4`)
- `php scripts/compute_dark_nights.php --days=60`
  - computes likely open dates with confidence and stores in `venue_dark_nights`

### Legacy compatibility

Legacy entrypoints are preserved and route to the new pipeline:

- `scrape_foopee.php` -> `scripts/sync_events.php --adapter=foopee`
- `scrape_venues.php` -> `scripts/sync_events.php --adapter=official`
- `scrape_all.sh` / `cron_sync.sh` now run Event Sync + scoring + dark-night jobs.

## Booking Workflow Setup Notes

### New app pages
- `/app/opportunities.php`
  - venue/admin: post and manage open-date opportunities
  - band/admin: browse open opportunities and submit inquiries
- `/app/bookings.php`
  - venue/band/admin: view booking pipeline, status, history, and notes
  - permitted users can transition booking status

### Booking lifecycle statuses
- `inquiry`
- `hold`
- `offer_sent`
- `accepted`
- `contracted`
- `canceled`
- `completed`

### State transition rules
- `inquiry -> hold|offer_sent|canceled`
- `hold -> offer_sent|canceled`
- `offer_sent -> accepted|canceled`
- `accepted -> contracted|canceled`
- `contracted -> completed|canceled`
- `completed` and `canceled` are terminal for non-admin users
- non-admin users can only mark `completed` on/after event date

### Booking workflow API
Under `/api/bookings/...`:
- `GET opportunities`
- `POST opportunities`
- `GET opportunities/{id}`
- `POST|PUT opportunities/{id}/status`
- `POST opportunities/{id}/inquiries`
- `GET mine`
- `GET requests`
- `GET {id}`
- `POST|PUT {id}/transition`
- `POST {id}/notes`

Legacy compatibility routes kept:
- `POST interest`
- `GET interests`

## Ticketing MVP Setup Notes

### Database config
Use environment variables (single switch point):

- `PB_DB_DRIVER=sqlite|mysql`
- `PB_DB_PATH=/absolute/path/to/booking.db` (sqlite)
- `PB_DB_HOST`, `PB_DB_PORT`, `PB_DB_NAME`, `PB_DB_USER`, `PB_DB_PASS`, `PB_DB_CHARSET` (mysql)
- `PB_DB_BOOTSTRAP_DEBUG=1` enables verbose migration/bootstrap logging
- `PB_DB_DEBUG=1` enables DB connection logging (secrets redacted)

If no variables are set, app defaults to SQLite at `data/booking.db`.

### Environment files
- App now loads `.env` and `.env.local` from project root automatically.
- Existing process-level env vars still take precedence.
- See `.env.example` for available payment/provider settings.

### Demo seed data
- `PB_ENABLE_DEMO_SEED=1` to insert demo users/profiles on bootstrap.
- `PB_DEMO_SEED_PASSWORD=...` to set demo account password.
- Demo users are **not** seeded unless explicitly enabled.

### Payment provider
- `PB_PAYMENT_PROVIDER=demo|stripe|square` (preferred switch; default is `demo`)
- `PB_PAYMENT_MODE=...` remains supported as a legacy alias.
- `PB_PUBLIC_BASE_URL=https://your-domain.test` (recommended for hosted checkout redirect/webhook URLs)
- `PB_STRIPE_SECRET_KEY=sk_test_...`
- `PB_STRIPE_PUBLISHABLE_KEY=pk_test_...` (optional for future client-side use)
- `PB_STRIPE_WEBHOOK_SECRET=whsec_...`
- `PB_STRIPE_WEBHOOK_TOLERANCE=300` (optional, seconds)
- `PB_STRIPE_API_BASE=https://api.stripe.com/v1` (optional override)
- `PB_SQUARE_ACCESS_TOKEN=...`
- `PB_SQUARE_APPLICATION_ID=...` (optional; useful for future client-side flows)
- `PB_SQUARE_LOCATION_ID=...`
- `PB_SQUARE_WEBHOOK_SIGNATURE_KEY=...`
- `PB_SQUARE_WEBHOOK_URL=https://your-domain.test/square-webhook.php` (must match Square dashboard webhook URL)
- `PB_SQUARE_API_BASE=https://connect.squareup.com` (optional override)
- `PB_SQUARE_API_VERSION=...` (optional; sends `Square-Version` header when set)
- `PB_APP_KEY=...` recommended for receipt token HMAC and other app-level signing.
  - If `PB_APP_KEY` is not set, app auto-generates a local key file at `data/.app_key` (or `PB_APP_KEY_FILE` path).

### Session and auth hardening
- `PB_SESSION_NAME=panicbooking_sid` (optional)
- `PB_SESSION_IDLE_TIMEOUT=7200` seconds
- `PB_SESSION_ABSOLUTE_TIMEOUT=86400` seconds
- `PB_SESSION_REGEN_INTERVAL=900` seconds
- `PB_SESSION_SAMESITE=Lax` (`Lax|Strict|None`)
- Login/signup forms and sensitive app/API writes now require CSRF tokens.
- API returns generic `500` errors for unhandled exceptions and logs server-side details.

### Password reset flow (minimal)
- App pages:
  - `/app/password-reset-request.php`
  - `/app/password-reset.php?token=...`
- API endpoints:
  - `POST /api/auth/password-reset-request`
  - `POST /api/auth/password-reset-confirm`
- Env:
  - `PB_PASSWORD_RESET_TTL_MINUTES=30`
  - `PB_PASSWORD_RESET_THROTTLE_SECONDS=120`
  - `PB_PASSWORD_RESET_DEBUG=1` (optional local debug mode to return reset URL in response)

## Ticketing Flow

### Venue/admin management
- `/app/events.php`: list/manage ticketed events and summary counts.
- `/app/event-edit.php?id=...`: create/edit an event.
- `/app/event-tickets.php?id=...`: add/edit ticket types, pricing, inventory, sales windows, activation.

### Public purchase
- `/event.php?slug=<event-slug>`
- Buyer selects quantities and enters name/email.
- In demo mode:
  1. pending order is created
  2. order is marked paid
  3. inventory is decremented safely
  4. one ticket row is created per admission
  5. receipt redirects to `/order-success.php`
- In stripe mode:
  1. pending order is created
  2. Stripe Checkout Session is created and buyer is redirected
  3. buyer returns to `/checkout-success.php` or `/checkout-cancel.php`
  4. webhook verification is the source of truth for setting paid/failed/canceled/refunded
  5. tickets are issued only when webhook-confirmed payment calls finalization
- In square mode:
  1. pending order is created
  2. Square Payment Link is created and buyer is redirected
  3. buyer returns to `/checkout-success.php`
  4. webhook verification is the source of truth for setting paid/failed/canceled/refunded
  5. tickets are issued only when webhook-confirmed payment calls finalization

### Ticket and QR
- `/order-success.php?order_id=...&receipt=...` renders one QR per ticket.
- QR payload is a validation URL containing an opaque `qr_token`.
- Each ticket also has a manual fallback `short_code`.

### Door check-in
- `/app/checkin.php?event_id=...`
- Camera scan (BarcodeDetector API) + manual token/code entry + search fallback.
- API validates ticket/event/order/status and prevents duplicate check-ins.
- Every check-in attempt is written to `checkins`.

### Stripe webhook
- Primary endpoint: `/stripe-webhook.php`
- API endpoint: `POST /api/payments/stripe_webhook`
- Signature is verified using `PB_STRIPE_WEBHOOK_SECRET`.
- Duplicate delivery is handled idempotently by `payment_webhook_events` (`provider + event_id` unique).

### Square webhook
- Primary endpoint: `/square-webhook.php`
- API endpoint: `POST /api/payments/square_webhook`
- Signature is verified using `x-square-hmacsha256-signature` + `PB_SQUARE_WEBHOOK_SIGNATURE_KEY`.
- `PB_SQUARE_WEBHOOK_URL` must match the exact webhook URL registered with Square.
- Duplicate delivery is handled idempotently by `payment_webhook_events` (`provider + event_id` unique).

## API Actions Added

Under `/api/ticketing/...`:

- `POST create_event`
- `POST|PUT update_event`
- `POST create_ticket_type`
- `POST|PUT update_ticket_type`
- `POST create_order`
- `POST mark_order_paid`
- `POST validate_ticket`
- `POST check_in_ticket`
- `GET search_ticket_by_code`

Under `/api/payments/...`:
- `POST stripe_webhook`
- `POST square_webhook`

## Security and Robustness

- Prepared statements used for ticketing DB writes/reads.
- CSRF token checks added for ticketing app forms and ticketing API mutating endpoints.
- Permission checks enforce venue/admin ownership for event/ticket/check-in operations.
- Inventory checks prevent overselling at payment-finalization time.
- Conditional ticket update prevents duplicate check-ins.
- Stripe/Square webhook signatures are verified before processing.
- Webhook events are logged with minimal metadata and deduplicated by `(provider, event_id)`.
- Ticket issuance remains inside a single transactional finalize path to prevent duplicate tickets.
- Maintenance/import/Event Sync scripts are CLI-first; web execution is disabled by default.
  - To explicitly allow web execution: `PB_ALLOW_WEB_MAINTENANCE=1` and `PB_MAINTENANCE_TOKEN=...`

## Schema / Migration

- Runtime DB bootstrap is centralized in:
  - `config/database.php` (driver/env config + PDO options + singleton connection)
  - `lib/db_bootstrap.php` (migration runner + legacy compatibility patches)
  - `api/includes/db.php` (connect, bootstrap, optional demo seed)
- Driver-specific bootstrap SQL lives in:
  - `db/migrations/sqlite/20260412_000_bootstrap.sql`
  - `db/migrations/mysql/20260412_000_bootstrap.sql`
- Applied migrations are tracked in `schema_migrations`.
- Existing historical SQL references remain in `db/migrations/*.sql` for context.

New tables:

- `events`
- `ticket_types`
- `orders`
- `order_items`
- `tickets`
- `checkins`
- `payment_webhook_events`
- `opportunities`
- `booking_requests`
- `bookings`
- `booking_status_history`
- `booking_notes`

## Assumptions

- `users.id` for a `type='venue'` user is used as `events.venue_id`.
- `users.id` for a `type='venue'` user is also used as `opportunities.venue_user_id`.
- each inquiry creates one `booking_request` + one `booking` at `inquiry` status.
- a band cannot create more than one active inquiry for the same opportunity.
- only one booking per opportunity can be in `accepted|contracted|completed` at a time.
- admin users may override normal status transitions.
- Ticketing is general admission only (no seats/transfers/resale/memberships).
- Demo mode treats purchase submission as successful payment.
- JSON profile data remains stored in `profiles.data` (text/longtext), and SQL JSON extraction is handled via driver-aware helpers.

## SQLite/MySQL Setup Notes

1. Set `PB_DB_DRIVER=sqlite` (default) or `PB_DB_DRIVER=mysql`.
2. For SQLite:
   - Set `PB_DB_PATH=/absolute/path/to/booking.db` (optional; defaults to `data/booking.db`).
3. For MySQL:
   - Set `PB_DB_HOST`, `PB_DB_PORT`, `PB_DB_NAME`, `PB_DB_USER`, `PB_DB_PASS`, and optionally `PB_DB_CHARSET`.
4. First request/script run auto-applies bootstrap migrations for the selected driver.
5. Maintenance scripts now use the same central DB bootstrap path as app/API.

## DB Troubleshooting

- Run the built-in bootstrap diagnostic:
  - `php scripts/debug_db_bootstrap.php`
- Force one-shot bootstrap:
  - `php -r 'require __DIR__ . "/api/includes/db.php"; echo "bootstrap ok\n";'`
- Set `PB_DB_BOOTSTRAP_DEBUG=1` to emit per-migration logs via `panicLog`.

## Migration Caveats / TODOs

- MySQL bootstrap targets modern MySQL 8+ behavior (including `CHECK` constraints support).
- Profiles and other JSON-like blobs are still text-backed; a future migration can promote selected fields to native JSON columns.
- Some maintenance scripts still assume scraped-event uniqueness on `(event_date, venue_name, bands)`; keep that unique key in MySQL.
- Historical one-off SQL files in `db/migrations/*.sql` are not yet wired into the new runner.

## Local Stripe CLI Testing

1. Set env vars:
   - `PB_PAYMENT_PROVIDER=stripe`
   - `PB_PUBLIC_BASE_URL=http://localhost:8000` (or your local URL)
   - `PB_STRIPE_SECRET_KEY=sk_test_...`
2. Start PHP server (example): `php -S localhost:8000`
3. Start Stripe listener and capture webhook secret:
   - `stripe listen --forward-to http://localhost:8000/stripe-webhook.php`
4. Set emitted secret in app env:
   - `PB_STRIPE_WEBHOOK_SECRET=whsec_...`
5. Trigger test events if needed:
   - `stripe trigger checkout.session.completed`
   - `stripe trigger payment_intent.payment_failed`
   - `stripe trigger charge.refunded`
