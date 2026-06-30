# Unread Alert System — Design Spec
**Date:** 2026-06-30  
**Status:** Approved  

---

## Overview

A single-file PHP system that runs as a cPanel cron job, polls Facebook Pages (via Meta Graph API) for unread Messenger messages, and sends formatted alerts to a Telegram channel. Includes a web-based Setup Wizard and Admin Dashboard — no manual file editing required.

---

## Goals

- Monitor ~10 Facebook Pages under one Business Manager for unread Messenger inbox messages
- Send Telegram alerts every 15 minutes (configurable) with page name + unread count
- Expose a clean JSON REST API so other systems can consume the unread data
- Restrict notifications to configurable office hours (or run 24/7 if desired)
- First-time setup via a beautiful step-by-step wizard UI

---

## File Structure

```
unreadAlert/
├── index.php        ← Entire system (setup wizard, dashboard, cron logic, API)
└── settings.json    ← Auto-created on first setup, never edit manually
```

---

## Routing

All routing is handled inside `index.php` via the `?action=` query parameter:

| URL | Description | Auth |
|-----|-------------|------|
| `index.php` | Setup Wizard (if not set up) or Admin Dashboard | None |
| `index.php?action=save` | POST — saves settings form | None (same-origin form) |
| `index.php?action=run&key=SECRET` | Cron trigger — fetch unread + send Telegram | Cron key |
| `index.php?action=api&key=API_KEY` | REST API — returns JSON unread data | API key |
| `index.php?action=test_telegram` | POST — sends a test Telegram message | None (admin) |
| `index.php?action=verify_page` | POST — verifies a page token via Graph API | None (admin) |

---

## Setup Wizard (First-Time Only)

Detected when `settings.json` does not exist. Five steps rendered as a single-page wizard (JS-driven step navigation, no page reloads between steps).

### Step 1 — Security Setup
- **Cron Secret Key** — text input with "Auto-generate" button (random 32-char hex)
- **API Key** — text input with "Auto-generate" button (random 32-char hex)

### Step 2 — Telegram Setup
- **Bot Token** — text input (user creates bot via @BotFather)
- **Channel ID** — text input (e.g. `@mychannel` or `-100xxxxxxxxx`)
- **[Test Message]** button — sends a live test message to confirm connectivity

### Step 3 — Facebook Pages
- Add rows: Page Name + Page ID + Page Access Token
- **[+ Add Another Page]** — dynamically adds a new row
- **[Verify]** per row — hits Graph API to confirm token is valid and returns page name

### Step 4 — Notification Settings
- **Check Interval** — dropdown: 5 / 10 / 15 / 30 / 60 minutes (default: 15)
- **Office Hours Mode** — toggle:
  - `Always ON` — notifications 24 hours, 7 days
  - `Custom Hours` — shows Start time, End time, Timezone dropdown (default: Asia/Dhaka)

### Step 5 — Done
- Displays the cPanel cron command (one-click copy):  
  `*/15 * * * * curl "https://domain.com/unreadAlert/index.php?action=run&key=SECRET"`
- Displays the API endpoint URL (one-click copy)
- **[Go to Dashboard]** button

---

## Admin Dashboard (After Setup)

Shown on every visit once `settings.json` exists.

```
┌──────────────────────────────────────────────┐
│  Unread Alert System                          │
│  Last Check: 10 minutes ago                  │
│  Next Check: ~5 minutes                      │
│  Status: ✅ Office Hours Active (9AM–6PM)    │
├──────────────────────────────────────────────┤
│  [Run Now]   [Settings]   [API Docs]         │
└──────────────────────────────────────────────┘
```

**Settings page** — same fields as the wizard, fully editable from the UI. Saves via POST to `?action=save`.

---

## Cron Logic (`?action=run`)

1. Authenticate request via `cron_key` query param — 401 if invalid
2. Load `settings.json`
3. **Office hours check:**
   - If `always_on = true` → proceed
   - If `always_on = false` → check current time in configured timezone against `start`/`end` — skip and exit silently if outside hours
4. For each page in settings:
   - Call `GET /{page-id}/conversations?platform=messenger&fields=unread_count&access_token={token}`
   - Paginate through all results, sum `unread_count` values where `unread_count > 0`
   - Also count number of conversations with `unread_count > 0`
5. Filter: only include pages where total unread > 0
6. If no pages have unread messages → no Telegram message sent (silent)
7. Build Telegram message (see format below) and POST to Bot API
8. Update `last_check` timestamp in `settings.json`

---

## Telegram Message Format

```
🔴 Unread Message Alert
🕐 2026-06-30 10:15 AM (Asia/Dhaka)
━━━━━━━━━━━━━━━━━━━━
📄 Page Name 1
   💬 5 unread messages
   🔗 https://business.facebook.com/latest/inbox/messenger?asset_id=PAGE_ID

📄 Page Name 2
   💬 12 unread messages
   🔗 https://business.facebook.com/latest/inbox/messenger?asset_id=PAGE_ID
━━━━━━━━━━━━━━━━━━━━
📊 Total: 17 unread across 2 pages
```

Only pages with unread > 0 appear in the message.

---

## REST API (`?action=api`)

Secured by `api_key` query param.

Returns **cached data** from the last cron run (stored in `settings.json`). Does NOT trigger a live Meta API call — this avoids rate limiting and keeps responses fast. Office hours do NOT restrict this endpoint; it always returns the latest cached result regardless of time.

### `GET index.php?action=api&key=API_KEY`

**Response (200 OK):**
```json
{
  "status": "ok",
  "checked_at": "2026-06-30T10:15:00+06:00",
  "office_hours_active": true,
  "total_unread": 17,
  "pages": [
    {
      "id": "123456789",
      "name": "Page Name 1",
      "unread_count": 5,
      "inbox_url": "https://business.facebook.com/latest/inbox/messenger?asset_id=123456789"
    },
    {
      "id": "987654321",
      "name": "Page Name 2",
      "unread_count": 12,
      "inbox_url": "https://www.facebook.com/987654321/inbox"
    }
  ]
}
```

`pages` array contains ALL pages from settings, with `unread_count: 0` for pages that have no unread messages. This lets other systems display a full page list.

**Response on auth failure (401):**
```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

---

## Settings JSON Schema

```json
{
  "cron_key": "random-32-char-hex",
  "api_key": "random-32-char-hex",
  "check_interval": 15,
  "office_hours": {
    "always_on": false,
    "start": "09:00",
    "end": "18:00",
    "timezone": "Asia/Dhaka"
  },
  "telegram": {
    "bot_token": "123456:ABC-DEF...",
    "channel_id": "@mychannel"
  },
  "pages": [
    {
      "id": "123456789",
      "name": "My Page",
      "token": "EAAxxxxx..."
    }
  ],
  "last_check": "2026-06-30T10:15:00+06:00",
  "last_result": [
    { "id": "123456789", "name": "My Page", "unread_count": 5 }
  ]
}
```

---

## Security Considerations

- `settings.json` must be outside webroot OR protected via `.htaccess` (`Deny from all`)
- Cron key and API key verified via `hash_equals()` to prevent timing attacks
- Page tokens never exposed in API responses or UI HTML source
- All user inputs sanitized before writing to JSON
- `.htaccess` auto-generated to block direct access to `settings.json`

---

## Meta Graph API Details

**Endpoint used:**
```
GET /v19.0/{page-id}/conversations
  ?platform=messenger
  &fields=unread_count
  &access_token={page_access_token}
```

**Required permissions on Page Access Token:**
- `pages_messaging`
- `pages_read_engagement`

**Pagination:** Follow `paging.next` cursor until exhausted. Sum all `unread_count` values.

---

## UI Design Principles

- Single HTML page, no external framework dependencies (vanilla CSS + JS only)
- Mobile-friendly, clean and modern look
- Bangla + English mixed labels acceptable
- Color scheme: dark sidebar, white content area
- Setup wizard: progress bar at top showing current step (1/5, 2/5, etc.)
- All sensitive fields (tokens, keys) use `type="password"` with show/hide toggle
