# PS13 — The lowercase pass

**P37**, ruled by the user on 2026-09-01 after the post-port browser sweep found the divergence
independently on five screens. The prototype writes every string it authors in lowercase; the
shipped app renders Title/Sentence Case. This slice ports the prototype's casing literally.

Runs **alone**. It touches copy on all eleven screens, so anything running beside it that edits a
string conflicts. It also lands after `PP4`, deliberately: several of the strings below only render
once the demo account has the data to show them, so a copy pass run earlier could not have been
verified complete.

## Goal

Every string the app authors reads as the prototype writes it. Not a blind `toLowerCase()`: the
rule is per-string judgement against the prototype, and the exclusions below are as load-bearing as
the inclusions.

### In scope

- **The eleven screens and their chrome.** A scan of `resources/js` found 292 candidate strings
  across 71 files. The scan was a working checklist, not the deliverable: it is line-based and
  therefore blind to multi-line JSX (see "What the browser pass caught"), so **the diff is the
  review artifact**, not a generated decision file. `F3`'s codemod bargain does not transfer here,
  because unlike a rename this change needs a judgement per string.
- **Data-derived strings**, which P37 explicitly includes: dates render `base · 12 jun – 4 sep`, not
  `Base · Sep 1 – Nov 30`. The choke points are `lib/pace.ts` (five `toLocaleDateString` sites),
  `lib/verdict.ts`, and the label maps — `WeekPlanWidget`'s `SESSION_TYPE_LABEL` / `PHASE_LABEL` /
  `STATUS_LABEL`, `InboxRow`'s `KIND_LABEL`, `inboxBuckets`' `BUCKET_LABEL`, `SessionBarGraph`'s
  `SEGMENT_LABEL`.
- **The two docs that state the superseded rule**: `docs/voice-and-tone.md` and
  `.claude/skills/temari/SKILL.md` both say UI chrome stays Title Case. Both are amended in this PR
  or the next agent starts from a rule the code no longer follows.

### Out of scope, and why

| excluded | reason |
|---|---|
| **Operator pages** — Devtools, Devtools/Design, AI-usage and `components/aiusage/`, `lib/designTokens.ts` | **P20**: operator tooling sits outside the product surface the prototype specifies. 84 of the 376 scanned strings. |
| **Legal pages** — terms, privacy, AI use, training disclaimer | Legal prose, not product copy. The prototype links to them but draws none of their content. |
| **Legal and safety copy rendered *inline* on a screen** — `DataUseStatement.php`'s "your data" bullets on Settings, `TrainingDisclaimer.php`'s card on Plan and Login | The same reasoning, one step further than expected. These are PHP-authored, appear inside the phone frame, and the browser pass flagged them as the most visible remaining Title Case. They stay capitalised anyway: the prototype draws **no** equivalent (its Settings fine-print section is a `LegalCard` of links only), so there is nothing to port, and lowercasing a medical disclaimer weakens copy whose whole job is to be read as serious. **A deliberate, reversible call** — if it should match, it is two constants. |
| **Share-card renderers** — `lib/shareCard.ts`, `lib/runcard.ts`, `RunCardImageRenderer.php` | An exported image, not a screen. The prototype draws no share card, and the client and server renderers must stay in step (see `PP2`'s correction to P11) — changing copy on one side alone widens a divergence this program has already had to untangle once. |
| **Small mono uppercase labels** (`text-label-micro` / `text-label-small`, eyebrows, stat captions) | The prototype uppercases these itself, and they render through a CSS `uppercase`, so **source case is invisible**. Editing them is pure churn with zero rendered effect. The user's steer on the mobile redesign was explicit that all-lowercase small labels look wrong. |
| **Proper nouns and domain terms** — Strava, HR, CTL, ATL, TRIMP, VDOT, GAP, PR, Z1-Z5, km, unit symbols | Running vocabulary, not chrome. `docs/voice-and-tone.md` already fixes these, and the prototype writes them the same way. |
| **LLM narration** | Not a source string. The persona already leans lowercase. |

**`Temari` the wordmark is in scope and is the one proper-noun exception**: `LoginScreen.tsx:90-93`
writes it lowercase, the app's own topbar already renders it lowercase on every other screen, and
Login's hero disagreeing with the rest of the app is a bug independent of P37. Found by the sweep.

## Files touched

The 71 files in the scan, plus `docs/voice-and-tone.md` and `.claude/skills/temari/SKILL.md`, plus
whatever component tests assert on visible text.

## Blockers

None. `PP4` is merged, so every surface this pass edits can actually be seen.

## Acceptance criteria

1. Every in-scope string reads as the prototype writes it, or is listed in the decision file with a
   reason for keeping its case.
2. No excluded category is touched. In particular `check:palette`, the mono label utilities and the
   share-card mirror files are byte-unchanged.
3. `docs/voice-and-tone.md` and `.claude/skills/temari/SKILL.md` no longer assert Title Case chrome,
   and say what the rule is now.
4. `./vendor/bin/sail composer check` green — expecting broad churn in tests that assert on visible
   text, which is the change landing, not a regression.
5. A browser pass over the screens with the densest copy (Login, Settings, Plan, History) confirms
   nothing reads as a mid-sentence lowercase accident.

## Coverage delta

Record before/after. Expected flat: this changes string values, not branches.

## Verification notes

- **Read the diff, and read it for what did *not* change.** Two mechanical attempts at this were
  reverted: a blanket substring replace across test files corrupted identifiers by matching `Sync`,
  `Card` and `Ask` inside `StravaSyncBadge`, `ShareCardModal` and `AskAboutRun`. Test expectations
  were ultimately updated from the suite's own failure output, one round at a time, which is slower
  but cannot invent a change no test asked for.
- **A blind lowercase is the failure mode here.** `Strava`, `HR`, `CTL` and every acronym survive,
  and so does any string that is a data value rather than chrome.
- Sentence-initial capitals inside *narrated* text are not this slice's business — that text comes
  from the narrator or the rule-based filler at runtime.

## What the browser pass caught

Run after the first two commits, over Login / Settings / Plan / History / Today. It confirmed the
Login wordmark fix and that lowercase dates read fine, and found the pass **incomplete** in a way
the scan structurally could not have caught:

- **Multi-line JSX text** — the scanner is line-based, so any paragraph broken across lines was
  invisible to it. That is what left `Save changes`, `Log out`, `Delete account`, `Heart-rate zones`,
  `This week's stats`, `This week's plan`, `Planned` / `Actual` and `What the plan can and cannot see`
  behind.
- **A second date formatter.** `pace.ts` was treated as *the* choke point; `verdict.ts:133` and
  `plan.ts:161` each call `toLocaleDateString` themselves. That produced the clearest tell: Today
  read "you're faster than you were in **April**" directly above "pace vs **apr** 5", and Plan showed
  "base · **sep** 1 – nov 30" above "WK 2 · **Sep** 7–13". Both now route through `.toLowerCase()`.
- **Backend-authored copy.** The scan only walked `resources/js`, so PHP-authored strings shown on a
  screen were never candidates. See the exclusion row above for the call taken there.

Two findings were checked and left alone as correctly out of scope: History's activity titles are
Strava-authored user data, and the recap card's sentence-cased text comes from the rule-based
narration filler.

## Open questions

1. **Do contractions come along?** The sweep found the shipped copy expands the prototype's
   contractions: `says when it can't tell` → "says when it cannot tell", `you've already done` →
   "you have already done" (`LoginScreen.tsx:34`, `:104`). That is a register change, not a casing
   one, so P37 does not obviously cover it. Raised here rather than silently folded in.
2. **`metricGlossary.ts`** is 32 of the in-scope strings and is the jargon-accessibility tier from
   `docs/voice-and-tone.md`. Its *terms* are technical and keep their case; its *definitions* are
   prose and follow P37. Worth confirming the split reads right in the tooltips rather than assuming.
