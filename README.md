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
- `PB_PAYMENT_MODE=stripe`: reserved hook; not implemented yet in this MVP.

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

### Ticket and QR
- `/order-success.php?order_id=...&receipt=...` renders one QR per ticket.
- QR payload is a validation URL containing an opaque `qr_token`.
- Each ticket also has a manual fallback `short_code`.

### Door check-in
- `/app/checkin.php?event_id=...`
- Camera scan (BarcodeDetector API) + manual token/code entry + search fallback.
- API validates ticket/event/order/status and prevents duplicate check-ins.
- Every check-in attempt is written to `checkins`.

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

## Security and Robustness

- Prepared statements used for ticketing DB writes/reads.
- CSRF token checks added for ticketing app forms and ticketing API mutating endpoints.
- Permission checks enforce venue/admin ownership for event/ticket/check-in operations.
- Inventory checks prevent overselling at payment-finalization time.
- Conditional ticket update prevents duplicate check-ins.
- Basic action/error logging via `error_log` for purchase/check-in failures.

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

## Assumptions

- `users.id` for a `type='venue'` user is used as `events.venue_id`.
- Ticketing is general admission only (no seats/transfers/resale/memberships).
- Demo mode treats purchase submission as successful payment.
- Existing legacy (non-ticketing) queries still include some SQLite-specific JSON usage and can be refactored separately for full MySQL parity.

## What To Do Next For Stripe Integration

1. Implement Stripe checkout/session creation in `api/includes/payment.php` (new `stripe` branch).
2. Keep `create_order` pending and store Stripe session/payment intent reference.
3. Add webhook endpoint to verify Stripe events and call `paymentFinalizeSuccessfulOrder(...)` only on confirmed payment.
4. Add idempotency keys + webhook signature verification.
5. Add failed/canceled payment state handling and buyer-facing retry UI.
