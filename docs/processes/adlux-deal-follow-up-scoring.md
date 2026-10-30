# Process — Adlux Deal Follow-Up Scoring

**Process name:** Adlux Deal Follow-Up Scoring
**Owner:** Peter
**Purpose:** Score every open Pipedrive deal 0–100 on how thoroughly it has been followed up, so the deals that have been *neglected* — especially high-value ones — surface at the top for action. The goal of the whole project (task 733) is forensic, ruthless follow-up of every opportunity; this process is the measuring stick for the "existing deals" branch.
**Status:** ⚙️ Live, semi-automated. Training in progress (round 1 done 30 Oct 2026).
**Last updated:** 30 October 2026

---

## 1. What it produces

A live board: **https://system.adlux.co.nz/follow-up-scores** (login required).

- Every open deal gets a **Follow-up score (0–100)** — higher = better chased, lower = neglected.
- RAG banding: **≥70 = 🟢 green · 40–69 = 🟠 amber · <40 = 🔴 red**.
- Ranked **worst-and-highest-value first**, by `value × (100 − score)`.
- A **Score date** column shows when each row was last calculated.
- **Sortable columns** (click a header to sort, click again to reverse) and a **per-deal concertina** — click any row to expand its six-factor breakdown inline (`score/max` + a reason per factor).
- First full run (30 Oct 2026): **669 open deals** — 197 red / 240 amber / 232 green, average 57.

The Whales page (system.adlux.co.nz, $30k+) and Mid-Market page ($4k–$30k) are separate views on the same engine and are left untouched by this board.

## 2. How to run it

- **Automatic:** recomputes **hourly** via the scheduler.
  - `routes/console.php`: `Schedule::command('followup:score')->hourly()->withoutOverlapping(30)->runInBackground();`
  - Gated by env **`FOLLOWUP_SCORE_ENABLED`** (true on prod).
- **Manual (CLI):** `php artisan followup:score --tz=Pacific/Auckland`
- **On demand (UI):** the **"Refresh scores now"** button on the board.
- Command code: `app/Console/Commands/FollowUpScore.php`. **Weights/thresholds:** `config/followup.php`.

## 3. The scoring factors

Six measurable signals from Pipedrive deal data. Max points and current thresholds:

| # | Factor | Max | Current thresholds |
|---|---|---|---|
| 1 | **Last-contact recency** | 25 | ≤7d=25 · ≤14d=22 · ≤30d=18 · ≤60d=12 · ≤90d=6 · older/never=0 |
| 2 | **Next action scheduled** | 20 | future=20 · overdue=8 · none=0 |
| 3 | **Touches logged** | 15 | ≥6=15 · ≥4=12 · ≥2=8 · 1=4 · 0=0 |
| 4 | **Channel mix** | 15 | call+email=15 · call-only=12 · email-only=5 · none=0 |
| 5 | **Who's actioning it** | 10 | actioned w/ named owner=10 · actioned no owner=7 · never=0 |
| 6 | **Time stuck in stage** | 15 | ≤30d=15 · ≤60d=11 · ≤120d=7 · ≤240d=3 · older=0 |

**Weights live in `config/followup.php`** (extracted from the command on 30 Oct 2026; behaviour-preserving). `FollowUpScore.php` reads every weight, bucket threshold and RAG cut-point from there, so **re-weighting is a one-file edit — no code change**. This is what makes the process genuinely *trainable*.

## 4. Training log

This process is **actively trained** — Peter reviews sample scores with their per-factor reasons and adjusts weights (or adds/removes factors). Each round is recorded here.

| Date | Round | Change made | Why |
|---|---|---|---|
| 2026-10-30 | 0 — baseline | Six factors live, initial weights (§3) | First build |
| 2026-10-30 | 1 — calibration | None yet — 5 deals scored with full per-factor breakdown for Peter's review | Calibrate against Peter's judgement |
| 2026-10-30 | 1b — infra | Weights/thresholds/RAG cut-points extracted from `FollowUpScore.php` into `config/followup.php` (behaviour-preserving; all sample scores unchanged) | Make re-weighting a one-file edit so the process is trainable |

**Round 1 finding (open decision):** a brand-new deal with a booked next-action but zero contact (e.g. Mayall Residence, 23A Puni St) still floors at ~35/RED, because recency + touches + channel + owner all read zero. If a *fresh, properly-queued* deal shouldn't look as bad as a *genuinely neglected* one, the levers are: (a) a grace period on deal/stage age for new deals, (b) more weight on "next action scheduled", or (c) leave as-is (no contact = genuinely un-chased). **Awaiting Peter's call.**

**How a training round works:**
1. Score a small, representative sample (spread of RAG bands and deal values).
2. For each, show the score **and the reason per factor**.
3. Peter reviews: scores right? Any factor over/under-weighted? Any missing?
4. Adjust weights/factors in config, re-run the sample, confirm, log the change here.

## 5. Open items

- [x] **Weights extracted to `config/followup.php`** (done 30 Oct 2026) — re-weighting is now a one-file edit.
- [ ] **Peter's round-1 calibration decision** on fresh-but-uncontacted deals (see §4).

## 6. Automation plan

- **Now:** semi-automated — scores recompute hourly; action is still manual (a human works the ranked list).
- **Next:** once scoring is trusted (training settled), wire the worst-and-highest-value deals into a daily prompt / task so they're actively chased, not just displayed.
- Tracked as a standing item (grouped under task 264, "Forensic analysis of deals using AI") so it doesn't get lost in the noise.
