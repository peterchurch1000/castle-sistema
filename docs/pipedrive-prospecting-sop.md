# Pipedrive Prospecting — Standard Operating Procedure

**Purpose:** find New Zealand building-industry companies — **builders, architects or roofers** — that are not already in Pipedrive, verify them, and create accurate organisation + person records.
**Last updated:** 14 August 2026

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
6. **Shared and pre-existing people need care.** Daryl Combes already existed under a related organisation (Ecotectural); Kirill Ilin legitimately runs two companies. Do not reassign or duplicate a person — link where valid, otherwise record the detail as an organisation note.
7. **Confirm the company actually does the work of the chosen category.** A builder must build; an architect must provide architectural design services (ideally a registered/licensed architect or practice); a roofer must install/repair roofing. PHD Building was rejected as a materials distributor. Reject category mismatches the same way — e.g. a draughting-only service is not an Architect, a roofing-materials retailer is not a Roofer.
8. **Duplicate and category checks cut across types.** A firm may already exist in Pipedrive under a *different* Company Type (a design-and-build company listed as Builder when you are prospecting Architects, or vice versa). A live-API hit on name, domain or email excludes the candidate regardless of which type it is filed under.
9. **Pipedrive rate limits are real.** ~40 requests per short window; 429s are throttling, not failure. Pace requests and read the rate-limit headers.
10. **Production DB guards block `DROP TABLE`.** Use uniquely named staging and backup tables. Pipe SQL scripts to `mysql` rather than interpolating through the shell — quoting has silently stripped table names before.
11. **Sessions get lost.** Context has been dropped mid-run more than once. Keep a running log of every org ID, person ID and outcome outside the chat.

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
verify them, and create a complete organisation record plus a linked person
record for each.

STEP 1 — SOURCE
Search the web for active NZ companies in the chosen CATEGORY:
- Builder: residential/commercial building companies
- Architect: registered/licensed architectural practices and architects
- Roofer: roofing installation and repair contractors
Prefer companies with their own website. Vary the region across batches
(Northland, Auckland, Waikato, BOP, Hawke's Bay, Taranaki, Manawatu,
Wellington, Nelson/Tasman, Canterbury, Otago, Southland) so coverage does not
cluster.

STEP 2 — QUALIFY
Reject a candidate unless its own first-party website confirms all of:
- it genuinely performs the chosen CATEGORY's work (a builder builds; an
  architect provides architectural design services; a roofer installs/repairs
  roofs) — NOT a materials supplier, developer, franchise portal, draughting-
  only service or generic tradesperson listing
- it is currently trading in New Zealand
- it has a named owner, director, principal or registered architect
- it publishes a direct email address AND a phone or mobile number
Note any former or trading names you find — you will need them in Step 3.

STEP 3 — DUPLICATE GATE (live Pipedrive, all candidates, before any write)
For every candidate check all three, against the live API and not PDClients,
and across ALL company types (a match under any Company Type still excludes):
  a. organisation name, normalised (case, punctuation, Ltd/Limited, "The",
     and any former or trading name)
  b. website domain, matched against the Website field
  c. contact email, matched against existing person records
Any hit on any of the three = exclude the candidate and find a replacement.
Re-run the full gate for the whole batch. Do not create a single record until
every candidate in the batch has passed.

STEP 4 — CREATE
Organisation:
  Name, Website, Company Type = the chosen CATEGORY's ID, Phone, Postal/street
  address, Area/region, Team size
Person (mandatory — an organisation without a linked contact is an incomplete
record):
  Name, Position, Mobile, Email, linked to the organisation just created
If the person already exists in Pipedrive under a different organisation, do
NOT reassign or duplicate them. Leave the existing link intact and record the
relationship as a note on the new organisation.
Leave any field blank if it is not published. Never estimate team size from
marketing language — count only named staff on the company's team page.
Pace requests to stay inside Pipedrive's ~40-per-window limit.

STEP 5 — VERIFY AND SYNC
Read every created record back from the API and confirm each field actually
landed, including that Company Type is the chosen CATEGORY's ID and a person is
linked. Report the readback, not the intent. If a write did not complete, say
so plainly rather than summarising it as done.
Then insert the organisations into PDClients with ID, name, CompanyTypeID,
CompanyType and Website.

STEP 6 — REPORT
For each company report: org ID, person ID, category, region, which fields
were populated, which were left blank and why. List every candidate excluded
and which of the three checks caught it (and, if the duplicate was filed under
a different Company Type, note which).
```

---

## Part 4 — Two things worth fixing before the next run

**Backfill the missing people — DONE (14 August 2026).** Organisations `11148`, `11149`, `11150` and `10869` now each have a linked contact (people `17443`–`17446`). No organisation in the `11064`–`11165` range is missing a person.

**Verify the earlier batches actually wrote.** Several batches in the transcript ended with the agent saying the run was "still active" or "waiting on the rate window", followed immediately by a success summary — with no visible readback in between. Before trusting the `11064`–`11147` range, pull those org IDs from the live API and confirm the phone, address, area, team size and linked person fields are genuinely populated rather than empty records with a name and website. A quick query on `PDClients` joined against a live API pull will show the gaps. **Update (14 August 2026):** linked-person coverage across `11064`–`11165` has been confirmed present for every org; the remaining unverified fields are phone, address, area and team size — not missing contacts.
