---
title: Legal pages (terms, privacy, AI use, training disclaimer)
description: The four public documents a stranger can read before connecting Strava, and the two code constants they are assembled from.
tags: [feature, legal]
status: living
reviewed: 2026-08-13
code_refs:
  - app/Http/Controllers/LegalController.php
  - app/Support/LegalDocuments.php
  - app/Support/TrainingDisclaimer.php
  - app/Support/DataUseStatement.php
  - resources/js/pages/Legal/Document.tsx
  - routes/web.php
---

# Legal pages

Four documents, all public and all unauthenticated, because the person who most needs to read them has not connected a Strava account yet: `/terms`, `/privacy`, `/ai-use`, `/training-disclaimer` ([web.php](../../routes/web.php), named `legal.*`). They sit outside both the `guest` and the `auth` group, so a signed-in user reading the privacy policy is not bounced to the dashboard the way `/login` bounces them.

One controller, one page component. [LegalController](../../app/Http/Controllers/LegalController.php) has a thin method per document and renders all four through [Legal/Document.tsx](../../resources/js/pages/Legal/Document.tsx) with the same payload shape (`slug`, `title`, `updated`, `intro`, `sections`). The page carries `bareLayout`, not the app shell, and imports nothing animated: `/login` and these pages are what an unauthenticated visitor loads, and the entry-chunk guard ([scripts/check-entry-chunks.mjs](../../scripts/check-entry-chunks.mjs)) polices that closure.

## Two statements the copy is not allowed to re-word

The wording that also appears *inside* the app lives in code, not in this note and not twice in the copy:

- **AI data use** — [DataUseStatement](../../app/Support/DataUseStatement.php), also rendered on Settings. `/ai-use` opens with it and `/privacy` embeds it as a section.
- **Not medical advice** — [TrainingDisclaimer](../../app/Support/TrainingDisclaimer.php). The Plan tab renders `TEXT` from a server prop rather than a local constant ([PlanController](../../app/Http/Controllers/PlanController.php) → [Plan.tsx](../../resources/js/pages/Plan.tsx)), `/training-disclaimer` uses it as the intro and expands on it with `scope()`, and `/terms` quotes it as a section.

[LegalDocumentsTest](../../tests/Unit/Support/LegalDocumentsTest.php) asserts both, so a second wording cannot quietly appear alongside the first. The remaining prose lives in [LegalDocuments](../../app/Support/LegalDocuments.php).

## The rule the copy is written to

Every claim has to be one the code keeps. Two places where that bit:

- **Deletion is not quite total.** [UserEraser](../../app/Services/User/UserEraser.php) deliberately keeps `ai_token_usages` and stamps the departing user's name and Strava athlete id onto those rows so the spend stays attributable. "Everything Temari stored about you goes with it" would therefore be stronger than what happens, so both surfaces name the exception rather than hedging: `DataUseStatement` in its deletion bullet, `/privacy` at length in its own paragraph. A test pins each.
- **There is no per-account AI switch.** `ai.enabled` is an app-wide [AppConfigKey](../../app/Support/Config/AppConfigKey.php); nothing scopes it per user. `/ai-use` says so outright instead of implying an opt-out exists.

## Where they are linked from

- Login page footer, as plain `<a>` elements rather than Inertia links, so they resolve even if the SPA runtime never boots ([Login.tsx](../../resources/js/pages/Auth/Login.tsx)).
- A "The fine print" section on [Settings](../../resources/js/pages/Settings/Index.tsx), above account deletion.
- Each document links to the other three, minus itself.

Related: [[strava-connect]] for the OAuth path these gate, [[settings]] for the in-app data-use blurb, [[plan-periodizer]] for the disclaimer's other rendering site.
