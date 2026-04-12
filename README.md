# Panic Booking

Lean PHP app for San Francisco venue/band booking + ticketing MVP.

## Ticketing MVP Setup Notes

### Database config
Use environment variables (single switch point):

- `PB_DB_DRIVER=sqlite|mysql`
- `PB_DB_PATH=/absolute/path/to/booking.db` (sqlite)
- `PB_DB_HOST`, `PB_DB_PORT`, `PB_DB_NAME`, `PB_DB_USER`, `PB_DB_PASS`, `PB_DB_CHARSET` (mysql)

If no variables are set, app defaults to SQLite at `data/booking.db`.

### Payment mode
- `PB_PAYMENT_MODE=demo` (default): creates paid orders immediately for development.
- `PB_PAYMENT_MODE=stripe`: uses Stripe Checkout + webhook-confirmed order finalization.
- `PB_PUBLIC_BASE_URL=https://your-domain.test` (recommended in Stripe mode for redirect/webhook URLs)
- `PB_STRIPE_SECRET_KEY=sk_test_...`
- `PB_STRIPE_PUBLISHABLE_KEY=pk_test_...` (optional for future client-side use)
- `PB_STRIPE_WEBHOOK_SECRET=whsec_...`
- `PB_STRIPE_WEBHOOK_TOLERANCE=300` (optional, seconds)
- `PB_STRIPE_API_BASE=https://api.stripe.com/v1` (optional override)

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

## Security and Robustness

- Prepared statements used for ticketing DB writes/reads.
- CSRF token checks added for ticketing app forms and ticketing API mutating endpoints.
- Permission checks enforce venue/admin ownership for event/ticket/check-in operations.
- Inventory checks prevent overselling at payment-finalization time.
- Conditional ticket update prevents duplicate check-ins.
- Stripe webhook signatures are verified before processing.
- Webhook events are logged with minimal metadata and deduplicated by Stripe event id.
- Ticket issuance remains inside a single transactional finalize path to prevent duplicate tickets.

## Schema / Migration

- Bootstrap schema is in `api/includes/db.php`.
- SQL reference migration file: `db/migrations/20260411_ticketing.sql`.

New tables:

- `events`
- `ticket_types`
- `orders`
- `order_items`
- `tickets`
- `checkins`
- `payment_webhook_events`

## Assumptions

- `users.id` for a `type='venue'` user is used as `events.venue_id`.
- Ticketing is general admission only (no seats/transfers/resale/memberships).
- Demo mode treats purchase submission as successful payment.
- Existing legacy (non-ticketing) queries still include some SQLite-specific JSON usage and can be refactored separately for full MySQL parity.

## Local Stripe CLI Testing

1. Set env vars:
   - `PB_PAYMENT_MODE=stripe`
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
