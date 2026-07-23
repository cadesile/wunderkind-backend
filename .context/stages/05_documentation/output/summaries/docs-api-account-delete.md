# docs/api/account-delete.md

> Title: API Spec — Delete Account · 410 words · parsed 2026-07-21T21:26:02.039Z

## Outline
- Request
- Responses
- Behavior / integration notes
- Example

## Summary
This API doc describes the `POST /api/account/delete` endpoint, which permanently and irreversibly deletes the authenticated user's account and all associated club data, using only the bearer token (no target-account param, no password re-confirmation). An agent should read this before implementing or reviewing any account-deletion client code, since it specifies the exact response contract (`200` success, `401` invalid token, `500` transactional failure/safe-retry) and required client-side behavior afterward (clear token/state, redirect to login, never reuse the token since it becomes non-functional post-delete despite being syntactically valid).
