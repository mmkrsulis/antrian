# Reka Queue Management Roadmap

| Milestone | Scope | Status |
|---|---|---|
| 01 | Docker foundation, PHP runtime, MariaDB, installer | Complete |
| 02 | Authentication, roles, sessions, CSRF, audit trail | Complete |
| 03 | Queue domain, daily sequences, kiosk ticket issuance | Complete |
| 04 | Operator workflow: call, recall, serve, complete, skip, cancel | Complete |
| 05 | Full-screen kiosk, operator console, public display, Indonesian announcements | Verification |
| 06 | Expanded administration: users, counters, display groups, settings | In Progress |
| 07 | Reporting, reset operations, backup and restore | Planned |
| 08 | XAMPP, printer hardware, security and production acceptance | Planned |

Allowed statuses are `Planned`, `In Progress`, `Verification`, `Complete`, `Blocked`, and `Deferred`.

## Current milestone

Milestone 05 is in verification. Its acceptance checks are:

- Kiosk renders a responsive button for every active service.
- Newly added active services appear without frontend code changes.
- Operator console uses a dedicated full-screen workspace.
- Public display uses a dedicated full-screen queue board.
- Display service panels are generated dynamically from the database.
- Ticket letters and digits are spoken as Indonesian words.
- Duplicate announcement protection remains active after refresh.
- Critical and authenticated smoke tests pass in Docker.
