# docs/frontend-integration.md

> Title: Frontend ↔ Wunderkind Backend — Integration Guide · 3250 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Environment configuration
- Auth flow
- Balance model
- Endpoints
-   `POST /api/register` — Public
-   `POST /api/login` — Public
-   `POST /api/academy/initialize` — JWT required (`ROLE_CLUB`)
-   `GET /api/academy/status` — JWT required (`ROLE_CLUB`)
-   `GET /api/squad` — JWT required (`ROLE_CLUB`)
-   `GET /api/staff` — JWT required (`ROLE_CLUB`)
-   `POST /api/sync` — JWT required (`ROLE_CLUB`)
-   `GET /api/leaderboard/{category}?period={period}` — JWT required
-   `GET /api/leaderboard/transfers/top-sellers?period={period}&limit={limit}` — Public
-   `GET /api/leaderboard/transfers/most-valuable?period={period}` — Public
-   `GET /api/market/data` — JWT required (`ROLE_CLUB`)
-   `POST /api/market/assign` — JWT required (`ROLE_CLUB`)
-   `GET /api/events/templates` — JWT required (`ROLE_CLUB`)
-   `GET /api/archetypes` — JWT required (`ROLE_CLUB`)
-   `GET /api/inbox` — JWT required (`ROLE_CLUB`)
-   `GET /api/inbox/{id}` — JWT required (`ROLE_CLUB`)
-   `POST /api/inbox/{id}/accept` — JWT required (`ROLE_CLUB`)
-   `POST /api/inbox/{id}/reject` — JWT required (`ROLE_CLUB`)
-   `POST /api/inbox/{id}/read` — JWT required (`ROLE_CLUB`)
-   `GET /api/finance/overview` — JWT required (`ROLE_CLUB`)
-   `GET /api/finance/investors` — JWT required (`ROLE_CLUB`)
-   `GET /api/finance/sponsors` — JWT required (`ROLE_CLUB`)
-   `POST /api/finance/sponsors/{id}/terminate` — JWT required (`ROLE_CLUB`)
-   `GET /api/facilities` — JWT required (`ROLE_CLUB`)
-   `POST /api/facilities/{type}/upgrade` — JWT required (`ROLE_CLUB`)
- CORS
- Wage & salary scale
- Enum reference

## Summary
This documents the REST API contract between the React Native frontend and the Wunderkind backend, covering env-based base URL config, JWT auth flow (register/login, token storage in MMKV, 401/403 handling), and the academy's balance/economy model (how income and expenses affect the pence-denominated balance). It also specifies request/response shapes and error codes for `/api/register`, `/api/login`, `/api/academy/initialize`, `/api/academy/status`, `/api/squad`, and `/api/staff`.

An agent should read this before implementing or modifying any frontend API client code, auth/token handling, or UI that displays balance, squad, or staff data — it's the source of truth for endpoint contracts and the balance-mutation rules.
