# Process — Adlux Deal Follow-Up Scoring

**Process name:** Adlux Deal Follow-Up Scoring
**Owner:** Peter
**Purpose:** Score every open Pipedrive deal 0–100 on how thoroughly it has been followed up **relative to the follow-up it actually warrants**, so neglected — especially high-value — deals surface at the top for action. The goal of the whole project (task 733) is forensic, ruthless follow-up of every opportunity; this process is the measuring stick for the "existing deals" branch.
**Status:** ⚙️ Live, semi-automated. Training in progress (rounds 1–2 done 30 Oct 2026).
**Last updated:** 30 October 2026

---

## 1. What it produces

A live board: **https://system.adlux.co.nz/follow-up-scores** (login required).

- Every open deal gets a **Follow-up score (0–100)** — higher = better chased, lower = neglected.
- RAG banding: **≥70 = 🟢 green · 40–69 = 🟠 amber · <40 = 🔴 red**.
- Ranked **worst-and-highest-value first**, by `value × (100 − score)`.
- A **Score date** column shows when each row was last calculated.
- **Sortable columns** (click a header to sort, click again to reverse) and a **per-deal concertina** — click any row to expand its six-factor breakdown inline (`score/max` + a reason per factor, including the cadence reason).
- **⏱ Follow-up cadences** button at the top opens the cadence editor (see §3).

The Whales page ($30k+) and Mid-Market page ($4k–$30k) are separate views on the same engine, untouched by this board.

## 2. How to run it

- **Automatic:** recomputes **hourly** via the scheduler.
  - `routes/console.php`: `Schedule::command('followup:score')->hourly()->withoutOverlapping(30)->runInBackground();`
  - Gated by env **`FOLLOWUP_SCORE_ENABLED`** (true on prod).
- **Manual (CLI):** `php artisan followup:score --tz=Pacific/Auckland`
- **On demand (UI):** the **"Refresh scores now"** button on the board (also applies any cadence edits).
- Command code: `app/Console/Commands/FollowUpScore.php`.
- **Two knobs, two homes:** factor **weights/thresholds** live in `config/followup.php` (code-side); **cadences** live in an editable DB table (UI-side, see §3).

## 3. The scoring factors

Six measurable signals from Pipedrive deal data. Max points:

| # | Factor | Max | Notes |
|---|---|---|---|
| 1 | **Last-contact recency** | 25 | **Cadence-relative** — see below |
| 2 | **Next action scheduled** | 20 | future=20 · overdue=8 · none=0 |
| 3 | **Touches logged** | 15 | ≥6=15 · ≥4=12 · ≥2=8 · 1=4 · 0=0 |
| 4 | **Channel mix** | 15 | call+email=15 · call-only=12 · email-only=5 · none=0 |
| 5 | **Who's actioning it** | 10 | actioned w/ named owner=10 · no owner=7 · never=0 |
| 6 | **Time stuck in stage** | 15 | ≤30d=15 · ≤60d=11 · ≤120d=7 · ≤240d=3 · older=0 |

Factor weights/thresholds live in `config/followup.php` (one-file edit, no code change).

### Cadence-relative recency (the core idea)

Recency is **not** scored on absolute days. Each deal has an **expected cadence** — how often it genuinely should be touched — and "overdue" means breaching *its own* cadence.

`ratio = days-since-contact ÷ cadence` → ≤1 = on cadence (**25/25**), 1.25×→22, 1.5×→18, 2×→12, 3×→6, beyond→0.
**Grace:** a deal <3 days old with no contact is not penalised on recency or touches.

**Cadence store** — DB table `follow_up_cadences` (31 seeded rows, all editable via the ⏱ editor). Resolution precedence: **Hot label (7d, tightest wins) › stage › pipeline › default (30d)**.

| Context | Cadence |
|---|---|
| **Hot** label (id 192) — override, any stage | 7 days |
| Quote Presented · Tendering | 30 days |
| Builder Awarded · Sub Contract | 42 days (6 wk) |
| Casual Deals | 60 days |
| In Construction · Confirming Details | 60 days |
| Concept / Pre-Consent stage | 90 days |
| Project On Hold (incl. AU; P3 "Inactive") | 270 days |
| Pre-Sale Concepts pipeline (whole) | 300 days |
| New-deal grace | 3 days |
| Anything unmapped | 30 days (default) |

The AU Leads pipeline mirrors the Pre-Sale pipeline by stage name.

### Cadence editor (UI)

The **⏱ Follow-up cadences** button at the top of the board opens an inline panel (Overrides/defaults · Pipeline · Per-stage). Every value is editable; **Save** writes to `follow_up_cadences`; the next **Refresh scores now** applies it. This is Peter's live training knob — no code or deploy needed.

## 4. Training log

Peter reviews sample scores with per-factor reasons and tunes cadences/weights. Each round recorded here.

| Date | Round | Change | Why |
|---|---|---|---|
| 2026-10-30 | 0 — baseline | Six factors live, initial weights | First build |
| 2026-10-30 | 1 — calibration | 5 deals scored with per-factor breakdown; surfaced that fresh/slow-stage deals were over-penalised by an absolute-days recency rule | Calibrate against Peter's judgement |
| 2026-10-30 | 1b — infra | Weights/thresholds extracted to `config/followup.php` (behaviour-preserving) | Make re-weighting a one-file edit |
| 2026-10-30 | 2 — cadence model | Recency made **cadence-relative**; editable `follow_up_cadences` store + ⏱ editor UI; 3-day new-deal grace; Hot=7d override | Judge each deal against the follow-up it actually warrants |

**Round 2 result:** RAG moved from 197🔴/240🟠/232🟢 (avg 57.0) to **191🔴/148🟠/331🟢 (avg 61.6)** across 670 deals. The amber→green shift is deals correctly recognised as *on cadence* for slow stages (On Hold, Concepts, Quote Presented); red barely moved, so genuinely neglected deals stay flagged. Worked examples validated On Hold, new-deal grace, Quote Presented and Hot-override cases.

**How a training round works:** score a representative sample → show score + reason per factor → Peter reviews, tunes cadences (⏱ editor) or weights (config) → Refresh → confirm → log here.

## 5. Open items

- [x] Weights extracted to `config/followup.php` (30 Oct).
- [x] Cadence-based recency + editable cadence store + editor UI (round 2, 30 Oct).
- [ ] Peter to **live-tune cadences** in the ⏱ editor as real usage reveals the right numbers.
- [ ] Future rounds: revisit the **other five factors** (e.g. should "next action scheduled" count more; channel-mix weighting) once cadence settles.

## 6. Automation plan

- **Now:** semi-automated — scores recompute hourly; action is still manual (a human works the ranked list).
- **Next:** once scoring is trusted, wire the worst-and-highest-value deals into a daily prompt / task so they're actively chased, not just displayed.
- Tracked as a standing item (grouped under task 264, "Forensic analysis of deals using AI") so it doesn't get lost in the noise.
