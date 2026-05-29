# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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

All UI text is in Spanish.

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
