---
title: Legal pages (terms, privacy, training disclaimer)
description: The three public documents a stranger can read before connecting Strava, and the two code constants they are assembled from.
tags: [feature, legal]
status: living
reviewed: 2026-09-29
code_refs:
  - app/Http/Controllers/LegalController.php
  - app/Support/LegalDocuments.php
  - app/Support/TrainingDisclaimer.php
  - app/Support/DataUseStatement.php
  - resources/js/pages/Legal/Document.tsx
  - routes/web.php
---

# Legal pages

Three documents, all public and all unauthenticated, because the person who most needs to read them has not connected a Strava account yet: `/terms`, `/privacy`, `/training-disclaimer` ([web.php](../../routes/web.php), named `legal.*`). The old `/ai-use` page is folded into `/privacy` as the "how Temari writes your notes" section; the route is a 301 to `/privacy#notes` (`LegalDocuments::NOTES_SECTION_ID`), which the page renders as the section's `id`. They sit outside both the `guest` and the `auth` group, so a signed-in user reading the privacy policy is not bounced to the dashboard the way `/login` bounces them.

One controller, one page component. [LegalController](../../app/Http/Controllers/LegalController.php) has a thin method per document and renders all three through [Legal/Document.tsx](../../resources/js/pages/Legal/Document.tsx) with the same payload shape (`slug`, `title`, `updated`, `intro`, `summary`, `sections`, each section with an optional `id` anchor). `summary` is the 3–5 line "the short version" block rendered above the sections; section headings are lowercase like the rest of the chrome, and the prose speaks as "we"/Temari with a single explicit "Temari is run by one person" line.. The page carries `bareLayout`, not the app shell, and imports nothing animated: `/login` and these pages are what an unauthenticated visitor loads, and the entry-chunk guard ([scripts/check-entry-chunks.mjs](../../scripts/check-entry-chunks.mjs)) polices that closure.

## Two statements the copy is not allowed to re-word

The wording that also appears *inside* the app lives in code, not in this note and not twice in the copy:

- **Data use** — [DataUseStatement](../../app/Support/DataUseStatement.php), also rendered on Settings and inside the login page's disclosure, which stays collapsed behind a one-line summary until tapped. `/privacy` embeds it as a section.
- **Not medical advice** — [TrainingDisclaimer](../../app/Support/TrainingDisclaimer.php). The Plan tab and the login page render the friend-voice `SHORT` line from a server prop, linking to `/training-disclaimer` ([PlanController](../../app/Http/Controllers/PlanController.php) → [Plan.tsx](../../resources/js/pages/Plan.tsx)), `/training-disclaimer` uses `TEXT` as the intro and expands on it with `scope()`, and `/terms` quotes it as a section.

No user-facing copy names the AI vendor or model: the statement and `/privacy` say "a third-party AI service" (an owner decision, accepted against sub-processor naming). `LegalDocumentsTest` pins the absence.

[LegalDocumentsTest](../../tests/Unit/Support/LegalDocumentsTest.php) asserts both, so a second wording cannot quietly appear alongside the first. The remaining prose lives in [LegalDocuments](../../app/Support/LegalDocuments.php).

## The rule the copy is written to

Every claim has to be one the code keeps. Two places where that bit:

- **Deletion is not quite total.** [UserEraser](../../app/Services/User/UserEraser.php) deliberately keeps `ai_token_usages` and stamps the departing user's name and Strava athlete id onto those rows so the spend stays attributable. "Everything Temari stored about you goes with it" would therefore be stronger than what happens, so `/privacy` names the exception in exactly one line of its deletion section, and `DataUseStatement` points there instead of restating it. A test pins that it appears once.
- **There is no per-account AI switch.** `ai.enabled` is an app-wide [AppConfigKey](../../app/Support/Config/AppConfigKey.php); nothing scopes it per user. `/privacy` says so outright instead of implying an opt-out exists.

## Where they are linked from

- Login page footer, as plain `<a>` elements rather than Inertia links, so they resolve even if the SPA runtime never boots ([Login.tsx](../../resources/js/pages/Auth/Login.tsx)).
- A "The fine print" section on [Settings](../../resources/js/pages/Settings/Index.tsx), above account deletion.
- Each document links to the other two, minus itself.

Related: [[strava-connect]] for the OAuth path these gate, [[settings]] for the in-app data-use blurb, [[plan-periodizer]] for the disclaimer's other rendering site.
