# Centralized API reference

This document is the single API index for Reka Queue Management. Replace
`https://queue.example.org` with the actual server URL. All JSON requests must
send `Content-Type: application/json`, unless an endpoint explicitly requires
`multipart/form-data`.

## API support levels

| Surface | Prefix | Intended consumer | Stability |
| --- | --- | --- | --- |
| Public integration | `/api/public` | PHP, WordPress, and other server-side websites | Supported external API |
| Native client | `/api/client` | Official Windows, Android, and Linux clients | Application API |
| Kiosk | `/api/kiosk`, `/api/tickets` | Reka Queue kiosk page/client | Application API |
| Operator | `/api/operator` | Authenticated operator web interface | Application API |
| Display | `/api/display` | Public-key or authenticated display | Application API |
| Admin | `/api/admin` | Authenticated administration interface | Internal API |

External integrations should use only `/api/public/*`. The other surfaces may
change together with official clients and are documented here for maintenance,
testing, and development.

## Response conventions

Successful resource responses normally use:

```json
{
  "data": {}
}
```

Polling endpoints may return top-level fields such as `cursor`, `tickets`, or
`events`. Errors use:

```json
{
  "error": "Human-readable error message."
}
```

Common status codes:

| Status | Meaning |
| --- | --- |
| `200` | Request succeeded |
| `201` | Resource or ticket created |
| `401` | Missing, expired, or invalid credential |
| `403` | Authenticated but not permitted for the requested service/counter |
| `404` | Resource not found |
| `419` | Missing or expired CSRF token; refresh the session endpoint |
| `422` | Invalid request data or queue transition |
| `429` | Public API rate limit exceeded |
| `503` | A required service, such as speech generation, is unavailable |

## Authentication matrix

| Mechanism | How to send it | Used by |
| --- | --- | --- |
| Public API key | `X-API-Key: <key>` | `/api/public/*` |
| Device token | `Authorization: Bearer <64-hex-token>` or `X-Device-Token` | `/api/client/*`, except registration |
| Web session + CSRF | Login cookie and `_csrf` body field or `X-CSRF-Token` | kiosk, operator, and admin mutations |
| Display key | `?key=<DISPLAY_ACCESS_KEY>` | public display polling and speech |
| Authenticated display scope | Login cookie and `?scope=mine` | operator service-only display |

Never place `ONLINE_API_KEY`, a device token, or `DISPLAY_ACCESS_KEY` in a
public repository. The online API key must be used by the integrating website's
server, not by browser JavaScript.

## Public integration API

The public integration API is limited to 60 requests per API client and source
IP per minute. An API key can come from `ONLINE_API_KEY` or an active database
API client. A database client may also restrict its allowed browser origin,
although server-to-server calls are recommended.

### List services

`GET /api/public/services`

```bash
curl -sS https://queue.example.org/api/public/services \
  -H 'X-API-Key: replace-with-a-private-key'
```

Returns active services that accept online registration.

### Check availability

`GET /api/public/availability?service_id={id}&date={YYYY-MM-DD}`

```bash
curl -sS 'https://queue.example.org/api/public/availability?service_id=4&date=2026-08-24' \
  -H 'X-API-Key: replace-with-a-private-key'
```

The response includes `capacity`, `registered`, `remaining`, and `available`.
`capacity` and `remaining` are `null` when the selected service has no daily
limit.

### Create an online registration

`POST /api/public/registrations`

```bash
curl -sS https://queue.example.org/api/public/registrations \
  -H 'X-API-Key: replace-with-a-private-key' \
  -H 'Content-Type: application/json' \
  --data '{
    "service_id": 4,
    "visitor_name": "Budi Santoso",
    "phone": "081200000000",
    "email": "budi@example.test",
    "identity_number": "",
    "notes": "Konsultasi berkas",
    "visit_date": "2026-08-24",
    "consent": true
  }'
```

The returned `registration_code` has the form `RQ-XXXXXXXX`. Preserve it for
status lookup and check-in. Consent must be `true`.

### Get a registration

`GET /api/public/registrations/{registration_code}`

```bash
curl -sS https://queue.example.org/api/public/registrations/RQ-12AB34CD \
  -H 'X-API-Key: replace-with-a-private-key'
```

### Check in

`POST /api/public/check-in`

```bash
curl -sS https://queue.example.org/api/public/check-in \
  -H 'X-API-Key: replace-with-a-private-key' \
  -H 'Content-Type: application/json' \
  --data '{"registration_code":"RQ-12AB34CD"}'
```

Check-in creates the live queue ticket exactly once. Retrying the request does
not intentionally create a second ticket for the same registration.

## Kiosk and ticket API

### Start or refresh a kiosk session

`GET /api/kiosk/session`

Returns `csrf` and `expires_in`. Keep the response cookie and send the CSRF
value when issuing a ticket.

### Issue a ticket

`POST /api/tickets`

```json
{
  "service_id": 4,
  "sub_service_id": 12,
  "_csrf": "session-csrf-token"
}
```

`sub_service_id` is optional. The server validates that the subservice belongs
to the selected service and stores its name on the ticket for historical and
printing consistency.

Ticket output URLs:

- `GET /ticket/{uuid}` — printable HTML ticket.
- `GET /ticket/{uuid}.pdf` — downloadable PDF ticket.

## Operator web API

These endpoints require an authenticated `super_admin`, `admin`, or `operator`
session. Operator service and counter permissions are enforced by the server.

| Method and path | Purpose | Important input |
| --- | --- | --- |
| `GET /api/operator/session` | Refresh session and CSRF token | none |
| `GET /api/operator/notifications?after={cursor}` | Poll new tickets and service/subservice waiting counts | cursor |
| `POST /api/operator/next` | Call the next ticket | `service_id`, `counter_id`, `_csrf` |
| `POST /api/operator/tickets/{id}/recall` | Announce the active ticket again | `counter_id`, `_csrf` |
| `POST /api/operator/tickets/{id}/serve` | Mark a called ticket as serving | `counter_id`, `_csrf` |
| `POST /api/operator/tickets/{id}/complete` | Complete a ticket | `counter_id`, `_csrf` |
| `POST /api/operator/tickets/{id}/skip` | Skip a ticket | `counter_id`, optional `reason`, `_csrf` |
| `POST /api/operator/tickets/{id}/cancel` | Cancel a ticket | `counter_id`, optional `reason`, `_csrf` |
| `POST /api/operator/notification-settings` | Save web notification settings/audio | multipart form + `_csrf` |

Notification settings accept `enabled`, `sound_type` (`chime`, `bell`, `beep`,
or `custom`), `volume` from `0` to `1`, and `play_mode` (`auto` or
`persistent`). A custom MP3, WAV, or OGG file may be uploaded as `sound_file`,
up to 10 MB. The web endpoint also accepts `trim_start` and `trim_end` in
seconds when server-side FFmpeg is available.

## Native Windows, Android, and Linux client API

### Register a device

`POST /api/client/notifications/register`

```json
{
  "username": "operator-name",
  "password": "operator-password",
  "device_id": "stable-device-identifier",
  "device_name": "Front Desk Android"
}
```

The response contains a 64-character device `token`, an event `cursor`, the
operator name, and `expires_at`. Store the token in the platform's protected
credential storage. Registration replaces the token for the same user/device
pair; other devices retain their own registrations.

For all subsequent native-client requests, send:

```http
Authorization: Bearer <device-token>
```

### Client endpoints

| Method and path | Purpose |
| --- | --- |
| `GET /api/client/notifications?after={cursor}` | New-ticket events, waiting counts, and synchronized sound settings |
| `GET /api/client/operator-state?service_id={id}&counter_id={id}` | Allowed services/counters, waiting count, and current ticket |
| `POST /api/client/operator-action` | Run `next`, `recall`, `serve`, `complete`, `skip`, or `cancel` |
| `GET /api/client/notification-settings` | Read cross-device notification settings |
| `POST /api/client/notification-settings` | Save cross-device notification settings |
| `POST /api/client/notification-audio` | Upload custom MP3/WAV/OGG audio, maximum 10 MB |

Operator action body:

```json
{
  "action": "next",
  "service_id": 4,
  "counter_id": 1,
  "ticket_id": 0
}
```

`ticket_id` is required for every action except `next`. Device tokens expire
after one year and immediately stop working when their user is disabled.

## Display API

### Full public-key display

- Page: `GET /display?key={DISPLAY_ACCESS_KEY}&fullscreen=1`
- Polling: `GET /api/display/events?key={DISPLAY_ACCESS_KEY}&after={cursor}`
- Indonesian speech WAV: `GET /api/display/speech?key={DISPLAY_ACCESS_KEY}&ticket={number}&counter={name}`

### Authenticated operator displays

- All services: `GET /operator/display?scope=all&fullscreen=1`
- Assigned services only: `GET /operator/display?scope=mine&fullscreen=1`
- Assigned-service polling: `GET /api/display/events?scope=mine&after={cursor}`

For an operator account, `scope=mine` is filtered server-side across service
summary cards, recent calls, and call events. Admin roles receive all services.
Display polling returns `events`, `recent`, `summary`, `media`, `header`, and
`footer_text`.

## Admin API

Admin routes require an authenticated `super_admin` or `admin` session and a
valid CSRF token. They are UI implementation endpoints, not supported external
integration endpoints.

| Method and path | Purpose |
| --- | --- |
| `POST /api/admin/display-settings` | Save display media, playlist, mute state, and header mode |
| `POST /api/admin/header-height` | Save `auto`/`fixed` header height (`60`–`300` px) |

## Polling and cursor rules

- Treat cursors as opaque, monotonically increasing integers.
- Send the last received cursor as `after` on the next request.
- Persist native-client cursors locally to avoid replay after restart.
- Update the cursor even when the returned event list is empty.
- On `401`, reconnect the native device or re-authenticate the web session.
- On `419`, refresh the kiosk/operator session to obtain a new CSRF token.
- Use a modest polling interval and exponential backoff for network failures.

## Security checklist

- Serve production traffic over HTTPS or a private Tailscale network.
- Keep API and display keys outside source control and client-side web code.
- Give each external system its own database API client when possible.
- Assign operators only to the services and counters they operate.
- Validate TLS certificates; do not disable certificate verification in clients.
- Do not log passwords, API keys, device tokens, or complete identity numbers.
- Revoke a compromised client key/device registration and rotate credentials.

See [Security](security.md), [Deployment](deployment.md), and the
[online-registration integration guide](online-registration-api.md) for focused
operational guidance. A ready-to-use server-side PHP wrapper is available at
[`integrations/php/RekaQueueClient.php`](../integrations/php/RekaQueueClient.php).
