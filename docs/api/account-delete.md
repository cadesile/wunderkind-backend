# API Spec — Delete Account

Permanently deletes the authenticated user's account and all data associated with
it. **Irreversible.**

## Request

```
POST /api/account/delete
```

| | |
|---|---|
| **Auth** | Required — JWT bearer token (`Authorization: Bearer <token>`). The account to delete is the one the token belongs to. |
| **Role** | `ROLE_CLUB` (standard game-client token). |
| **Body** | None. |
| **Query params** | None. |

There is **no way to specify a target account** — a user can only delete their own.
No password re-confirmation is required by the API; a valid token is sufficient (put
any "are you sure?" confirmation in the client UI).

## Responses

| Status | Body | Meaning |
|---|---|---|
| `200 OK` | `{ "success": true }` | Account and all its data were permanently deleted. |
| `401 Unauthorized` | *(firewall response)* | Missing, invalid, or expired token. Nothing was deleted. |
| `500 Internal Server Error` | `{ "success": false }` | Deletion failed. **The operation is transactional — on failure nothing is deleted and the account remains fully intact.** Safe to retry. |

## Behavior / integration notes

- **Deletes:** the user account, every club they own, and all club data (match
  results, season records/snapshots, sponsors, investors, sync records, leaderboard
  entries, inbox messages). This is a **hard delete** — there is no undo and no
  recovery.
- **On `200`, the client must:** discard the stored JWT, clear all local/cached
  state for the user, and route to the logged-out (login/landing) screen. Do not
  reuse the token.
- **Token after deletion:** JWTs are stateless, so the old token stays
  *syntactically* valid until it expires — but since the account no longer exists,
  **every subsequent authenticated request will return `401`**. Don't rely on the
  token for anything after a successful delete; drop it immediately.
- **On `500`:** the account still exists (transactional rollback). You may surface a
  "couldn't delete, try again" message and allow a retry.

## Example

```js
async function deleteAccount(token) {
  const res = await fetch('/api/account/delete', {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
  });

  if (res.status === 200) {
    // Success — wipe local session and send the user to the login screen.
    clearAuthToken();
    clearLocalState();
    navigateToLogin();
    return;
  }
  if (res.status === 401) {
    // Token invalid/expired — treat as logged out.
    clearAuthToken();
    navigateToLogin();
    return;
  }
  // 500 — account NOT deleted; safe to retry.
  showError('Could not delete your account. Please try again.');
}
```
