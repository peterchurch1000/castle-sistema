# Pipedrive Prospecting — Standard Operating Procedure

**Purpose:** find New Zealand building-industry companies — **builders, architects or roofers** — that are not already in Pipedrive, verify them, create accurate organisation + person records, then enter each new company into the pipeline as a linked lead carrying a pending follow-up call.
**Last updated:** 19 August 2026

This SOP covers three prospecting categories. **Pick one category per run** and apply the identical qualification, live duplicate-gate, person-linking and accuracy rules to it. Nothing about the process changes between categories except the definition of what qualifies (Step 2) and the Company Type set on the record (Step 4).

### Categories and their Pipedrive Company Type IDs

| Category to run | Company Type | ID | Related types (use only if the company genuinely fits) |
|---|---|---|---|
| **Builder** | Builder | **19** | Passive House Builder `105` |
| **Architect** | Architect | **18** | Landscape Architect `103`, Passive House/Homestar Architect `104` |
| **Roofer** | Roofer | **20** | — |

Company Type is the Pipedrive organisation field with key `6d61829c9e8147a1b7d79c7e5ec04edd111de8e0` (24 values in total; the ones above are the relevant subset). Set the **primary** category ID for the run; only use a related variant when the company's own site clearly matches it (e.g. a firm that markets itself specifically as a Passive House architect).

---

## Part 1 — What has already been established

### Systems and access

| Item | Detail |
|---|---|
| Server | Adlux server, `170.64.248.224`, user `root`, key `~/.ssh/id_rsa_PBC` |
| Application | `/var/www/html/Adlux_Production` |
| Database | MySQL `adlux_production` |
| Local mirror table | `PDClients` |
| Pipedrive API key | `/var/www/html/Adlux_Production/.env` (`PIPEDRIVE_API_TOKEN`) |

### State of the data

- `PDClients` was originally a stale, orphaned 4,979-row snapshot. No application code, view, trigger, procedure, foreign key or cron job referenced it. (`PDClientID` columns in `Quotes` / `QuoteLineEntries` *are* active, but they are not joined to `PDClients`.)
- It has been rebuilt from the live Pipedrive Organizations API to ~7,052 organisations, plus the records added since.
- Old table preserved as `PDClients_backup_20260813_1408`.
- Current columns: org ID, name, `CompanyTypeID`, readable `CompanyType`, `Website`, Pipedrive update timestamp, local sync timestamp. Indexed on ID, name and company type.
- Company Type is a Pipedrive field with 24 values. **Builder = ID 19, Architect = ID 18, Roofer = ID 20.** Others include Installer, Plumber, Trade Store, Aluminium Glazer, Engineer, Developer.
- Type coverage at last sync: ~2,445 Builders, 1,855 Architects, 499 Roofers, 491 Trade Stores; 107 organisations with no type.
- Website coverage: ~3,803 populated, ~3,249 empty. Values are stored exactly as entered in Pipedrive, so some are email addresses or social links rather than domains.

### Records added so far

Organisations `11064`–`11165`, all classified as Builder, **each with a linked person record**. The gap previously flagged here — `11148` White Wood Homes, `11149` Drum Construction, `11150` Stu Bell Construction and `10869` Home Construction, originally created without a contact — was **closed on 14 August 2026** with linked people `17443`–`17446` (Malachi Farrant, Michael Drum, Stu Bell, Paul Nanai). The later batches added `11151`–`11165`, each also with a linked person. As of 14 August 2026 no organisation in the `11064`–`11165` range is missing a contact.

Architect and Roofer prospecting runs have not yet started — the existing counts above are the pre-existing Pipedrive population, not additions from this process.

---

## Part 2 — Hard-won lessons (do not relearn these)

1. **The local mirror is not authoritative.** Home Construction was absent from `PDClients` but already existed in Pipedrive as org `10869`. Every duplicate check must hit the **live API**, immediately before writing.
2. **Name-only duplicate checks are insufficient.** Hidden duplicates were caught only by website domain or contact email: Whippet Builders, Willie Davis Builders, Scott Construction Marlborough, White Worx (under its full legal name), Substrait Construction, McMahon Builders, JRA Construction, Davies Homes, Proactive Build.
3. **Check trading names and former names.** Revered Homes was formerly Building Logic.
4. **Gate the whole batch before creating anything.** Several batches created one or two records, then hit a duplicate on the third and left the batch half-written and unsynced.
5. **Never invent a field.** Team size means the number of staff explicitly named or counted on the company's own team page — not an inference from marketing copy. If address or headcount isn't published, leave it blank. (Example: Plantation Builds publishes only "Wellington", so its address is `Wellington, New Zealand` and no street was fabricated.)
6. **An existing person is not automatically a duplicate-company exclusion.** This database is more than ten years old, so a person may have left an old employer, founded a new enterprise, or now legitimately hold roles in multiple companies. An exact mobile match establishes identity, not current employment. Run the Career-change review below before deciding whether to retain, reassign or document multiple affiliations. Never create a second person merely to avoid investigating the existing record.
7. **Confirm the company actually does the work of the chosen category.** A builder must build; an architect must provide architectural design services (ideally a registered/licensed architect or practice); a roofer must install/repair roofing. PHD Building was rejected as a materials distributor. Reject category mismatches the same way — e.g. a draughting-only service is not an Architect, a roofing-materials retailer is not a Roofer.
8. **Duplicate and category checks cut across types.** A firm may already exist in Pipedrive under a *different* Company Type (a design-and-build company listed as Builder when you are prospecting Architects, or vice versa). A live-API hit on name, domain or email excludes the candidate regardless of which type it is filed under.
9. **Pipedrive rate limits are real.** ~40 requests per short window; 429s are throttling, not failure. Pace requests and read the rate-limit headers.
10. **Production DB guards block `DROP TABLE`.** Use uniquely named staging and backup tables. Pipe SQL scripts to `mysql` rather than interpolating through the shell — quoting has silently stripped table names before.
11. **Sessions get lost.** Context has been dropped mid-run more than once. Keep a running log of every org ID, person ID and outcome outside the chat.
12. **A named decision-maker is key prospecting data.** Prefer an owner or managing director, then a current director, general manager, construction manager, or another clearly senior person responsible for building projects. The company website does not need to publish the person's full name if an authoritative external source establishes their identity and current role. Never infer a surname or role.
13. **Make a reasonable, documented effort to find the selected contact's mobile number.** A verified mobile is highly valuable but is not an absolute qualification requirement. Only attach a number when a reliable source explicitly associates it with the exact named contact. Never substitute another employee's mobile, a company landline or an unattributed number. If no confidently matched mobile is found, leave it blank and record the sources checked.
14. **Use an evidence hierarchy for externally established contacts.** The New Zealand Companies Register is authoritative for current directors; LinkedIn can establish a current role when the profile clearly identifies the company; professional bodies, awards profiles, reputable trade publications and verified company social accounts may corroborate identity and role. Record the source used in the run log. Search-result snippets, generic directories and data-broker profiles are leads, not sufficient evidence on their own.
15. **Keep company and personal contact data distinct.** A generic address such as `info@`, `admin@` or `team@` may be stored as the person's work/company email when it is the only published company address, but do not describe it as their direct or private email. A company landline is not a person's mobile number and must not be labelled as one. Never infer an email address or mobile number from a pattern.
16. **A changed company-domain email is a career-change trigger, not proof by itself.** When the same person now uses a different company's domain, investigate their present role. Give greatest weight to the NZ Companies Register, the new company's current website, a clearly matched current LinkedIn profile, relevant professional/licensing records and current official company social accounts. Also check the former employer's current team page. Generic or personal email changes are weaker signals.
17. **Preserve history while keeping the primary organisation current.** Pipedrive permits one primary organisation on a person. When strong current evidence establishes that the person has moved, update that primary link to the current company and preserve the former affiliation in dated notes on the person and both organisations. Retain a still-valid personal email as secondary; replace an obsolete work-domain email rather than presenting it as current. If evidence supports simultaneous active roles, do not reassign automatically: retain the most commercially relevant/current primary organisation and document the other active role with sources.
18. **Create the lead and its call activity as one complete unit — never land a lead without its pending call.** A review of recent batches found this week's additions had leads + linked contacts but **no pending call activity** (only 4 of 140 carried one), while an earlier Jul–Aug batch had the opposite gap (activities present, leads missing on 34 of 187). A lead with no pending call is incomplete (Step 4) and silently drops out of the follow-up pipeline. After POSTing each lead, immediately POST its `call` activity and read BOTH back before moving to the next org; if the activity write fails, treat the lead as unfinished and retry, do not proceed. Verify at batch end that every new lead has exactly one open call activity.

### Career-change review for an existing person

Run this review whenever a candidate company leads to a person already in Pipedrive under another organisation. The person match pauses the ordinary duplicate gate; it does not by itself exclude the candidate company.

1. **Confirm identity.** Require an exact, person-matched identifier such as mobile number, direct email, LinkedIn profile, or an unambiguous full-name/role combination. An identical mobile is particularly strong, but it must be explicitly associated with that named person.
2. **Establish the current role.** Check, in order: NZ Companies Register; the candidate company's current website; a clearly matched LinkedIn profile; professional or licensing records; reputable industry profiles; current verified company social accounts.
3. **Check the former organisation.** Review its current website/team page and other recent authoritative evidence. Absence is supporting evidence, not conclusive proof by itself.
4. **Classify the relationship:**
   - **Same company/trading-name duplicate:** exclude the candidate organisation.
   - **Verified move or new enterprise:** the candidate company may qualify; reuse and reassign the existing person after the whole batch passes, update current work details, and preserve the former affiliation in notes.
   - **Verified simultaneous roles:** do not create a duplicate person. Choose the most current/commercially relevant primary organisation and record the additional active relationship in notes.
   - **Unclear or conflicting evidence:** do not reassign. Hold the candidate for manual review and record the sources checked.
5. **Evidence threshold for reassignment.** Email change alone is insufficient. Normally require at least two mutually consistent current signals, including one strong source (Companies Register, current first-party company page, or clearly matched current LinkedIn profile). An exact mobile plus a current directorship/role source normally meets the threshold.
6. **Audit trail.** Before changing anything, record the old organisation, old work email, evidence URLs and access date. After reassignment, add a dated note to the person, former organisation and new organisation. Do not delete historical activities or valid secondary contact details.

---

## Part 3 — The instruction to give the model

> Paste the block below at the start of a new session. **Fill in the CATEGORY line first.**

```
ROLE
You are compiling new prospects into Adlux's Pipedrive CRM. Accuracy matters
more than volume. A wrong or duplicated record costs more than a missed one.

CATEGORY (choose exactly one for this run)
  Builder   -> Company Type ID 19
  Architect -> Company Type ID 18
  Roofer    -> Company Type ID 20
Set CATEGORY = <Builder | Architect | Roofer> and use its ID everywhere Step 4
says "Company Type". Do not mix categories in a single run.

ACCESS
- Adlux server: root@170.64.248.224, key ~/.ssh/id_rsa_PBC
- Pipedrive API key: /var/www/html/Adlux_Production/.env (PIPEDRIVE_API_TOKEN)
- Local mirror: MySQL adlux_production, table PDClients
- Company Type field key: 6d61829c9e8147a1b7d79c7e5ec04edd111de8e0
Use the Pipedrive API, not the browser UI. The browser session logs out and
stalls the run.

TASK
Find N New Zealand companies in the chosen CATEGORY that are not in Pipedrive,
verify them, create a complete organisation record plus a linked person record
for each, and enter each new company into the pipeline as a linked lead that
carries a pending follow-up call.

STEP 1 — SOURCE
Search the web for active NZ companies in the chosen CATEGORY:
- Builder: residential/commercial building companies
- Architect: registered/licensed architectural practices and architects
- Roofer: roofing installation and repair contractors
Prefer companies with their own website. Vary the region across batches
(Northland, Auckland, Waikato, BOP, Hawke's Bay, Taranaki, Manawatu,
Wellington, Nelson/Tasman, Canterbury, Otago, Southland) so coverage does not
cluster.

Use the company's own website to establish that it is active in New Zealand,
performs the chosen CATEGORY's work, and publishes company contact details.
The website may identify the decision-maker by first name or role only. When it
does not publish a complete name and current role, use authoritative external
sources in this order:
  1. New Zealand Companies Register for current directors
  2. LinkedIn profiles that clearly show a current role at the company
  3. professional bodies, awards profiles or reputable trade publications
  4. verified company social-media accounts
Record the identity/role source in the running log. Search-result snippets,
generic directories and data-broker profiles can identify leads but cannot be
the sole evidence used to create a person record.

STEP 2 — QUALIFY
Reject a candidate unless its own first-party website confirms all of:
- it genuinely performs the chosen CATEGORY's work (a builder builds; an
  architect provides architectural design services; a roofer installs/repairs
  roofs) — NOT a materials supplier, developer, franchise portal, draughting-
  only service or generic tradesperson listing
- it is currently trading in New Zealand
- it publishes a company email address and company phone number
Reject a candidate unless first-party or authoritative external evidence also
establishes a fully named, current decision-maker, using this preference order:
owner or managing director; current director; general manager or construction
manager; another clearly senior person responsible for building projects.

Make a reasonable, documented effort to find a mobile number for that exact
person. The company can still qualify if no mobile is found. Only use a mobile
when the source explicitly matches the number to the selected contact's full
name. If the match is uncertain or the number belongs to someone else, do not
use it. Never infer a surname, current role, mobile number or email pattern.
Note any former or trading names you find — you will need them in Step 3.

STEP 3 — DUPLICATE GATE (live Pipedrive, all candidates, before any write)
For every candidate check all three, against the live API and not PDClients,
and across ALL company types (a match under any Company Type still excludes):
  a. organisation name, normalised (case, punctuation, Ltd/Limited, "The",
     and any former or trading name)
  b. website domain, matched against the Website field
  c. contact email, matched against existing person records
A name or domain hit showing the candidate is the same organisation = exclude
the candidate and find a replacement. A contact-email or person hit under a
different organisation is not an automatic exclusion: pause that candidate and
run the Career-change review in Part 2. Classify it as same-company duplicate,
verified move/new enterprise, verified simultaneous roles, or unclear. Re-run
the full gate for the whole batch. Do not create or reassign a single record
until every candidate in the batch has passed and every person hit is resolved.

STEP 4 — CREATE
Organisation:
  Name, Website, Company Type = the chosen CATEGORY's ID, Phone, Postal/street
  address, Area/region, Team size
Person (mandatory — an organisation without a linked contact is an incomplete
record):
  Name, Position, Mobile, Email, linked to the organisation just created
If the person already exists in Pipedrive under a different organisation, never
create a duplicate person. Follow the Career-change review. For a verified move
or new enterprise, reuse the existing person, change their primary organisation
to the verified current company, update current work email/position, retain a
still-valid personal email as secondary, and add dated evidence notes to the
person and both organisations preserving the former affiliation. For verified
simultaneous roles, choose the most current/commercially relevant primary
organisation and document the other active role. If the evidence is unclear,
make no reassignment and hold the candidate for manual review.
Leave any field blank if it is not published. Never estimate team size from
marketing language — count only named staff on the company's team page.
Make a reasonable effort to populate the linked person's mobile, but leave it
blank unless a reliable source explicitly associates the number with that exact
named person. Do not put a company landline, another employee's mobile or an
unattributed number in the Mobile field. If the only published email is a
generic company address (`info@`, `admin@`, `team@`, etc.), it may be stored as
the person's work/company email, but report it accurately as generic rather
than direct or private. Record the sources checked for identity, role and mobile
in the running log, including when no matched mobile was found.

Lead (mandatory — every new organisation must enter the pipeline as a lead):
After the organisation and its linked person are verified and read back, create
exactly ONE Pipedrive lead per new organisation (POST /api/v1/leads):
  - Title = "V4 Lead - " + organisation name
  - organization_id = the organisation just created
  - person_id = that organisation's linked contact — the lead MUST be linked to
    the company contact, not the organisation alone
  - owner_id = 429316 (Peter Church)
Idempotency: before creating, GET /leads?organization_id=<id> and skip/patch if a
"V4 Lead - <name>" lead already exists, so a re-run never duplicates leads.

Impending call activity (mandatory — a lead must not sit idle): create ONE
follow-up activity ON that lead (POST /api/v1/activities):
  - type = call
  - subject = "Intro call – " + organisation name
  - lead_id = the lead just created; also set org_id and person_id
  - owner_id = 429316 (Peter Church)
  - due_date = the next New Zealand business day (skip weekends and NZ public
    holidays); done = 0
A record is only complete once the organisation, linked person, linked lead AND
pending call activity all exist. An organisation with no lead, a lead with no
linked contact, or a lead with no pending call activity is incomplete.

Pace requests to stay inside Pipedrive's ~40-per-window limit.

STEP 5 — VERIFY AND SYNC
Read every created record back from the API and confirm each field actually
landed, including that Company Type is the chosen CATEGORY's ID, a person is
linked, the "V4 Lead - <name>" lead exists linked to both the organisation and
the contact (owner 429316), and the lead carries an open call activity due on the
next NZ business day. Report the readback, not the intent. If a write did not
complete, say so plainly rather than summarising it as done.
Then insert the organisations into PDClients with ID, name, CompanyTypeID,
CompanyType and Website.

STEP 6 — REPORT
For each company report: org ID, person ID, lead ID, call-activity ID and its
due date, category, region, which fields were populated, which were left blank
and why, whether the person's email is direct or a generic company address, and
the sources used to establish the person's identity, current role and any mobile
number. When mobile is blank,
list the sources checked. List every candidate excluded
and which of the three checks caught it (and, if the duplicate was filed under
a different Company Type, note which). For every existing-person hit, report
the Career-change classification, evidence reviewed, whether the primary
organisation changed, and how the former or simultaneous affiliation was
preserved.
```

---

## Part 4 — Two things worth fixing before the next run

**Backfill the missing people — DONE (14 August 2026).** Organisations `11148`, `11149`, `11150` and `10869` now each have a linked contact (people `17443`–`17446`). No organisation in the `11064`–`11165` range is missing a person.

**Verify the earlier batches actually wrote.** Several batches in the transcript ended with the agent saying the run was "still active" or "waiting on the rate window", followed immediately by a success summary — with no visible readback in between. Before trusting the `11064`–`11147` range, pull those org IDs from the live API and confirm the phone, address, area, team size and linked person fields are genuinely populated rather than empty records with a name and website. A quick query on `PDClients` joined against a live API pull will show the gaps. **Update (14 August 2026):** linked-person coverage across `11064`–`11165` has been confirmed present for every org; the remaining unverified fields are phone, address, area and team size — not missing contacts.

---

## Part 5 — When to stop builders and switch to roofers

Run **one category at a time** (Step 4 in Part 3). Builders come first; only move on when NZ builders are genuinely exhausted, not merely harder to find.

**Exhaustion rule (the trigger):** declare NZ builders exhausted when **two consecutive full runs return zero net-new qualifying builders** — i.e. every candidate found is already in Pipedrive (caught by the live duplicate gate) or fails Step 2 qualification — **and those two runs used different, well-covered regions** so a single thin region isn't mistaken for national exhaustion. A run that yields even one net-new qualifying builder resets the counter to zero.

**Mechanics:**
- Track a `consecutive_zero_yield_runs` counter in the running log. Each run records: region(s) searched, candidates examined, net-new created, duplicates hit, qualification rejects. Increment on a genuine zero-net-new run across a fresh region; reset to zero on any run that adds a builder.
- Before counting a run as zero-yield, confirm the region was actually well-covered (a spread of towns/cities, not one suburb) — thin coverage is not exhaustion.
- When the counter reaches **2**, STOP. Do not switch silently: post a note/question to Peter — "NZ builders appear exhausted (2 consecutive zero-yield runs across <regions>); switching CATEGORY to Roofer" — and only then change CATEGORY to **Roofer (Company Type ID 20)** for subsequent runs. Everything else in the SOP is identical.
- Apply the same rule again to end the roofer phase before any further category.

---

## Part 6 — Daily "new companies added" report

Once a day, email Peter a short summary of what the search system added to Pipedrive.

**Recipient / time:** `peter@adlux.co.nz`, 08:00 NZ time, every day. Sent by a scheduled server job on the Adlux server (a report to Peter himself) — not sent ad-hoc from a chat session.

**Source of truth:** organisations created by this process. Identify them by `add_time` and creator (the prospecting API token / user) rather than a hard-coded ID range, so the count stays correct as new batches land.

**Content:**
- **New since yesterday:** count of organisations added in the last 24h, broken down by Company Type (Builder / Architect / Roofer / other).
- **Running total since the automated search began:** cumulative count added by the process, with the same Company-Type breakdown (e.g. "153 builders to date").
- One line per category so the mix is visible at a glance (e.g. "Builders 153, Architects 0, Roofers 0").
- Optional: how many of the day's additions have a linked contact / a mobile / a lead + pending call, to surface any incomplete records early.

**Status:** to be built as a scheduled job (see the linked Organiser task). Until then this section is the spec.
