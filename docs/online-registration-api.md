# Online registration API

The module supports a native form at `/online-registration` and external PHP/WordPress websites through JSON REST endpoints. External clients must send `X-API-Key` on every request. Set a strong `ONLINE_API_KEY` in `.env`; do not expose it in browser JavaScript. Calls should be made by the website server.

## Lifecycle

1. Fetch active services.
2. Optionally check capacity for a date.
3. Create a registration with visitor consent.
4. Keep the returned `RQ-XXXXXXXX` code.
5. On the visit date, check in using the code. This creates the live queue ticket exactly once.

## Endpoints

- `GET /api/public/services`
- `GET /api/public/availability?service_id=1&date=2026-08-09`
- `POST /api/public/registrations`
- `GET /api/public/registrations/{code}`
- `POST /api/public/check-in`

Registration request:

```json
{"service_id":1,"visitor_name":"Budi Santoso","phone":"08123456789","email":"budi@example.test","identity_number":"","notes":"Konsultasi berkas","visit_date":"2026-08-09","consent":true}
```

Check-in request:

```json
{"registration_code":"RQ-12AB34CD"}
```

Responses use `{ "data": ... }`; errors use `{ "error": "..." }` with an appropriate HTTP status. API access is limited to 60 requests per client/IP per minute. See `integrations/php/RekaQueueClient.php` for a server-side PHP client.
