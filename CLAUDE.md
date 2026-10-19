# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Response style

Answer in the minimum number of words possible — aim for under 30 words by default. Lead with the answer, skip preamble. Longer replies are fine only when the subject genuinely cannot be explained briefly (e.g. multi-step instructions or a conceptual explanation the user asked to understand).

## Asking Peter a question — FIXED FORMAT (every room, always)

<!-- QFORMAT_GLOBAL_V1:START — remove this block to revert -->
This applies in **EVERY** room and chat — task or not, GSD-loop or not. Whenever a turn's purpose is to put a **question / decision / approval** to Peter, render it in the chat bubble as this EXACT raw HTML (the bubble renderer runs `marked` with HTML passed straight through, no sanitiser — inline `style` works; do NOT wrap it in a code fence). Only **one** question per turn.

```html
<div style="font-size:1.3em;font-weight:700;margin:0;">Objective</div>
<div style="margin:2px 0 0;">{overarching objective — 15 words max}</div>

<div style="font-size:1.3em;font-weight:700;margin:20px 0 0;">Question</div>
<div style="margin:2px 0 0;">{the ~85-word explanation; use <ul><li>…</li></ul> for bullets and <b>…</b> for emphasis}</div>

<div style="margin:14px 0 0;">{the single one-line question?}</div>
```

Fixed styling — must not drift: **Objective** and **Question** are `1.3em` bold headings (no "The" before "Question"); the paragraph sits tight under each heading (`margin:2px`, no blank line); a `margin-top:20px` gap sits above **Question** (where a divider would be) with NO horizontal rule; `margin-top:14px` space before the final one-line question. Enforced by the Stop hook `.claude/hooks/qformat-guard.py`, which also lights the task's red dot straight from this chat question — no `room-ask.sh` card needed.
<!-- QFORMAT_GLOBAL_V1:END -->

## Commands

```bash
# Full dev environment (server + queue + log viewer + vite hot reload)
composer dev

# Run all tests
composer test

# Run a single test file
php artisan test tests/Unit/ExampleTest.php

# Lint/format PHP with Pint
./vendor/bin/pint

# Database migrations
php artisan migrate

# Build frontend assets
npm run build
```

First-time setup:
```bash
composer setup   # installs deps, copies .env, generates key, migrates, installs npm, builds assets
```

## Architecture

**Single-purpose Laravel 13 app** that displays a real-time sales performance dashboard for Castle's sales team. There is no auth — the app is deployed internally.

### Data flow

1. **Sales data** is pulled on demand from NetSuite via a RESTlet (OAuth 1.0 / HMAC-SHA256). The `POST /refresh` endpoint calls `DashboardController::refresh()`, which hits the NetSuite Restlet at `https://{OAUTH_REALM}.restlets.api.netsuite.com/...?script=921&deploy=1` using the saved search ID `customsearch2305` (MTD Invoices by Sales Rep).
2. Results are stored in `sales_data` (columns: `rep_name`, `year`, `month`, `sales_amount`, `fetched_at`). The table has a unique constraint on `(rep_name, year, month)` — each refresh deletes and re-inserts the current month's rows.
3. **Quotas** live in `sales_quotas` (same shape) and are managed manually — there is no UI to edit them.

### Pace calculation

`DashboardController::calcPaceRatio()` computes what fraction of the month's working days have elapsed, using Argentine public holidays fetched from `https://date.nager.at/api/v3/PublicHolidays/{year}/AR` with a hardcoded fallback list. All timestamps use `America/Argentina/Buenos_Aires` (UTC-3, no DST).

### Frontend

The dashboard is a single Blade view (`resources/views/dashboard.blade.php`) with:
- Vanilla JS + Chart.js (loaded from CDN) rendering a horizontal bar chart of sales % attainment per rep
- Tailwind CSS 4 via Vite
- Auto-refresh every 20 min during Argentine working hours (Mon–Fri 08:00–20:00 BA time)
- A `POST /refresh` fetch call wired to the "Actualizar desde NetSuite" button

### Database

SQLite (`database/database.sqlite`). Tests use an in-memory SQLite database (`DB_DATABASE=:memory:` in `phpunit.xml`).

### Required environment variables

```
OAUTH_REALM              # NetSuite account ID (e.g. 4294049)
OAUTH_RESTLET_CONSUMER_KEY
OAUTH_RESTLET_CONSUMER_SECRET
OAUTH_RESTLET_TOKEN
OAUTH_RESTLET_TOKEN_SECRET
OAUTH_VERSION=1.0
```

### Legacy file

`CastleDirectRestlet.php` at the repo root is a legacy standalone script from a different system (references `NetsuiteMasterCommand`, `table_update_log`, etc.). It is not wired into this Laravel app.

<!-- ASSOC_ASK_V1:START — remove this whole block to revert -->
## Raising questions on an associated task (red-dot cards)

**Applies ONLY when this room is working a specific Organiser task** — i.e. it was opened from a task ("+ New Room"/"+ New Conference Room" on a task), or you were explicitly told to work `task-NNN`. In an ordinary chat with no associated task, ignore this section entirely.

### Finding your associated task

The room↔task link is authoritative in the **`task_rooms`** table, keyed by this room's **session UUID** — NOT in `~/workspace/tasks/`, where the task `.md` is often absent. Resolve it directly before doing anything task-scoped (raising a question, marking done).

⚠️ **Do NOT key on `$CCB_ROOM_KEY`.** That env var is frequently empty or holds a `draft-c-…` draft id, whereas every `task_rooms.room_key` is the session UUID (e.g. `7f44c556-…`). Keying on `$CCB_ROOM_KEY` silently matches nothing and makes the room wrongly report "no task linked" even when the link exists. Use the session UUID you already know (your transcript filename / session id), falling back to the env var only if it happens to be a UUID:

```bash
source ~/autonomy-lib-db.sh
# ROOM_UUID = this room's session UUID (the 8-4-4-4-12 id). Prefer $CCB_ROOM_KEY only if it is already a UUID.
ROOM_UUID="<this-room's-session-uuid>"
case "$CCB_ROOM_KEY" in [0-9a-f]*-*-*-*-*) ROOM_UUID="$CCB_ROOM_KEY";; esac
TID=$(dbq -e "SELECT task_id FROM task_rooms WHERE room_key='$ROOM_UUID' ORDER BY seq DESC LIMIT 1;")
dbq -e "SELECT bridge_short_id,title,done,bridge_file FROM tasks WHERE id='$TID';"   # bridge_short_id = the NNN you see in the UI
```

If `TID` comes back empty, do NOT conclude "no task is linked" — first confirm you used the session UUID, not an empty/draft `$CCB_ROOM_KEY`.

If that returns a row, you ARE working an associated task even if no `task-NNN.md` exists locally. `~/gsd-status.sh done` already uses this same link to flip `tasks.done` — so declaring `done` now genuinely completes the task row.

When you are working an associated task and you need a **decision, approval, or information from Peter to go further**, simply **ask him the question in the chat, in the fixed Objective/Question format** (see below). That alone raises the red dot: the Stop hook `.claude/hooks/qformat-guard.py` detects that your turn ends with a question and self-declares the room's `question` state, so the task row shows red and the dot clears automatically on the next turn that doesn't end with a question. **No card is required.**

You *may* additionally file a persistent Q&A card so the question also appears in Details → Questions (❓ badge) — useful for a decision you want to survive on the task record — but it is **optional**, not the trigger for the dot:

```bash
# OPTIONAL persistent card — the red dot already shows from the chat question alone.
# find the task file (match on the task id or slug)
ls ~/workspace/tasks/task-*.md | grep <id-or-slug>

# raise ONE question — first line is the objective, blank line, then the question.
# Total ≤100 words: ~15 for the objective, ~85 for the question.
~/room-ask.sh ~/workspace/tasks/<task-file>.md "<objective — 15 words max, what we're ultimately driving toward>

<one single question, about 85 words, everything Peter needs to decide this one thing>"
```

Rules:
- **One question at a time.** Raise a single question, then STOP and wait — his answer comes back on the task; continue next turn.
- **Format exactly:** line 1 = the objective in ≤15 words; a blank line; then ONE question of ~85 words (≤100 words total including the objective line). Keep long detail (diffs, findings) in the task's Loop Log, not in the question.
- The red dot is **chat-driven**: it lights whenever your turn ends with a correctly-formatted question and clears when it doesn't. The optional `room-ask.sh` card applies deterministic anti-loop guards (post-answer quiet period + normalized dedup) and prints `suppressed-*` if you re-ask an answered/pending card — never rephrase and re-ask a card; if suppressed, just wait.
<!-- QFORMAT_V1:START -->
### How to present the question in chat (HTML)

See the top-level section **"Asking Peter a question — FIXED FORMAT (every room, always)"** — the same fixed HTML applies here. The formatted chat question is what lights the red dot (via the Stop hook); a `room-ask.sh` card is optional. Only ONE question per turn.
<!-- QFORMAT_V1:END -->

### Declaring your state (GSD Loop)

By default every task is **in the GSD Loop** — the regime where the room reports its own status and formats its own questions. A task opts out via the single **GSD Loop** tick on its menu (frontmatter `gsd_loop: off`); for an opted-out task, skip this whole section.

At the END of every turn, declare your own state so the task row shows the right indicator — the room reports its status; nothing infers it:

```bash
~/gsd-status.sh waiting "drafted the reply, awaiting your go-ahead"   # paused on Peter / external — healthy
~/gsd-status.sh done    "deployed and verified"                        # task finished
~/gsd-status.sh parked  "nothing more until the client replies"        # intentionally stopped
```

States: `waiting` (on Peter or an external event — calm, never flagged as stuck) · `done` · `parked`. A live turn shows as **working** automatically. You do **not** declare a `question` state by hand — the Stop hook sets it (red dot) whenever your turn ends with a formatted question and clears it otherwise, so a `gsd-status.sh waiting/done/parked` you set on a non-question turn is never stomped.

<!-- ASSOC_ASK_V1:END -->
