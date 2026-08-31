# C1 — CI + guard audit

**Wave** 3 · **Slot** worktree (slot 1) · **Blockers** none · **Status** in-review

## Goal

Collapse the three competing definitions of "am I done" into one, and produce an evidence-backed
keep/cut/fix verdict on every guard in the repo.

The trigger was two red-CI round-trips in a single day (`166527b0`, `208d004e`) on a slice whose
author had a green local gate. Neither was carelessness. `composer check` did not run
`check-doc-citations.php` or `check-see-references.php`, so no command existed that could tell an
author they were done. `PS1`-`PS11` and `PP4` — twelve slices, all in worktrees — were about to
inherit the same trap.

Scoped by the user: *"maybe we should add new slice that grills our ci so it can be much more
efficient, removes clutter"*, and on scope: *"i dont think we need check indonesian, and i bet there
is more that we dont actually need."*

**This slice does not cut guards.** It gathers the evidence and recommends; the cuts are the user's
to make off the table below. Two rulings arrived during the work and are implemented here:
`NoEmDashInPromptsTest` is cut, and all nine legacy 301 redirects are deleted.

---

## The verdict table

`fired?` means: is there evidence in git history, or in a slice doc's own acceptance record, of this
guard failing on a genuine regression and forcing a fix to non-guard code? `silent?` means: if the
guard were gone, would the bug it catches announce itself some other way, or would it ship quietly?

A guard that has never fired is only a cut candidate when its failure mode is also *loud*. Where
`silent? = yes` and `fired? = no`, the honest reading is "we have not regressed there yet", not
"there is nothing to catch" — `check-entry-chunks.mjs` is the clearest case.

| guard | what it catches | fired? | friction | silent? | risk | verdict | evidence |
|---|---|---|---|---|---|---|---|
| `DesignTokenContrastTest` | sub-AA contrast on either ground, incl. translucent panels and ink-on-tint | **yes, 5+** | low | **yes** | **R1** | **KEEP — do not remove** | `98997c0f` (8 of 12 inks measured 4.20–4.34 while the single-ground audit said pass), `9dd7ff2a`, `f54441a1` (PaceCell at 1.50:1 in-browser), `a48a3512`, `83435de1` (rarity flags 2.40–4.49). Five measured, shipped a11y bugs fixed in three days |
| `check-raw-palette.mjs` | raw Tailwind shades / off-token shadows at call sites | **yes** | low | **yes** | R10 | **KEEP** | `1e271535` extended it to Blade at 10:47; `8e3e1b4c` at **10:48** fixed four Pulse views off raw palette classes, "45 occurrences in strava-health alone" |
| `check-doc-citations.php` | docs citing code that moved or is gone | **yes** | med → now low | **yes** | R7 | **KEEP** | `1e55003c` added line-drift checking ("thirty citations had already drifted"); `dc4d5408` next, body: *"Found by the line-checking guard."* Caused one of today's two round-trips **because it was not in `composer check`** — now it is |
| `check-see-references.php` | `{@see}` naming a deleted class | **yes** | med → now low | **yes** | R7 | **KEEP** | `166527b0` (this week) — a mascot-removal slice orphaned a `@see` in `GamificationProps.php`. Its own commit message: *"runs inside backend-tests, not composer check, so a green local gate misses it"*. Exactly today's failure; now in `composer check` |
| `structure.test.ts` (FE 1:1) | a new `.tsx`/`.ts` shipping without a co-located test | **yes** | low | no | R3 | **KEEP** | `d70aa5c7` — function coverage slipped to 94.54% and reddened main after a merge on a moved base; 9 components got real tests, `EXEMPT` shrank to one entry (`app.tsx`) and has not grown since |
| `check-entry-chunks.mjs` | a chart/map/animation engine landing on the first-paint path | no | low | **yes — loudest case** | **R6** | **KEEP** | Never fired post-creation, but it is the **only** gate that reads the built bundle. Its creation commit (`4343b60f`) documents two already-shipped regressions it would have caught: Rolldown sweeping `react` into `charts`, and `bareLayout` dragging framer-motion onto Login. Removing it re-admits both silently |
| `check-indonesian.php` | Indonesian re-entering an English-only app; stale exemptions | **yes** | **was high, now low** | partly | — | **KEEP (narrowed) — user's call** | `208d004e` — a doc rewrite reintroduced "aksesori" after its redirect was deleted; the stale-`ALLOWED` rule caught it. See the dedicated section below: it is **not** left with nothing after the redirect deletion |
| `DesignTokenDocsTest` | docs/CLAUDE.md/SKILL.md describing removed tokens | no | low | **yes** | **R9** | **KEEP** | R9 is live, not theoretical: SKILL.md documented a dead gold palette, so *"every agent-driven slice starts primed with a dead palette"*. `F2` extended this test's loop to SKILL.md as R9's named mitigation. Twelve agent-driven slices remain |
| `DesignTokenMirrorsTest` | hand-copied hex in canvas/Blade/PHP drifting off the token | maintenance only | med — §8 coupler | **yes** | — | **KEEP** | `69fd23af`: the fail-closed `OFF_TOKEN` allowlist forced cleanup of 17 dead entries during a deletion. Nothing imports the mirrors, so drift is undetectable any other way |
| `EveryClassHasATestTest` | a new backend class with no test | by design, no | **highest churn** (32 commits) | no | R7, §8 | **KEEP** | Decision 7 pins it. It rarely produces a fix-commit because it is satisfied in the same commit that adds the class — absence of catches is the guard working, not idling. Exemption list is 7 entries and flat since `e7e35a1d` (2026-06-07) |
| `AppLayoutAssetsExistTest` | `asset()` pointing at a file that is not in `public/` | borderline | low | **yes** | — | **KEEP + fix registration** | Written after `og-default.png` was referenced for months while never existing, breaking every link preview with nothing failing. **Finding: it carries no `->group('structure')`**, so the fast gate never runs it — see Open questions |
| `check-raw-palette.test.ts` | the palette guard's own rules silently going empty | n/a (meta) | none | **yes** | **R10** | **KEEP — copy this pattern** | R10's mitigation, delivered. Asserts the rule set is exactly the two the docstring documents and that removing rule 3 was deliberate. The only guard-on-a-guard in the repo |
| `cssIntegrity.test.ts` | an unterminated CSS comment swallowing live rules | — | none | **yes** | — | **KEEP** | Cheap; failure is otherwise invisible until a style stops applying |
| `build-tokens-dark.test.ts` | `darkGrounds()`/`inkOnDark()` derivation regressions | — | none | **yes** | **R1** | **KEEP** | R1's derivation half, paired with the contrast test's scoring half |
| `typescript:enums --check` + `GenerateTypeScriptEnumsCommandTest` | `generated.ts` drifting from the PHP enums | — | none | **yes** | §8 | **KEEP** | §8 names `generated.ts` as regenerated by two slices at once |
| `secret-scan` (gitleaks action) + pre-commit gitleaks | credentials entering history | — | low | **yes** | — | **KEEP** | Unconditional in CI and staged-only locally. Not reproducible in `composer check` (host binary) |
| `nightly-audit.yml` | vulnerable composer/npm deps | — | none | **yes** | — | **KEEP** | `composer audit --locked` + `npm audit --omit=dev --audit-level=high`. Off the PR path entirely; costs nothing |
| `.githooks/commit-msg` | non-Conventional-Commit subjects | — | low | no | — | **KEEP** | Cheap, local, deterministic |
| `.githooks/pre-push` | direct pushes to `main` | — | none | no | — | **KEEP** | Backs up branch protection |
| `.githooks/pre-commit` | main-branch commits, secrets, and **full** phpstan/tsc/eslint per commit | — | **highest local cost** | mixed | — | **KEEP — but see Open questions** | The main-branch and gitleaks halves are cheap and unique. The full phpstan + full tsc + full eslint per *commit* is now entirely duplicated by `composer check` pre-push |
| `ci-gate` | a required job that did not pass or cleanly skip | — | none | **yes** | — | **KEEP** | The only check in branch protection (verified: `required_status_checks.contexts == ["ci-gate"]`). Everything else is advisory, which is why renaming `docs-citation-guard` was safe |
| ~~`NoEmDashInPromptsTest`~~ | em/en-dash in `app/Services/AI` string literals | **yes — see below** | low | no | — | **CUT (user ruled)** | Cut on the user's instruction, *"i dont really interested in hardprompting no emdash anymore i think"* — a deliberate de-escalation, not a "never caught anything" cut |

### Two rows that need their own paragraph

**`NoEmDashInPromptsTest` did fire, contrary to the first pass of the archaeology.** A history sweep
finds no fix-shaped commit for it, which reads as "never caught anything". That is a search
artefact: slices squash-merge, so an in-slice catch leaves no separate commit. `B4`'s own acceptance
record ([12-B4-plan-narration.md](12-B4-plan-narration.md), line 114) states the guard *"caught and
fixed a real violation across all three during implementation"*. The cut still stands — the user's
own standing note calls the em-dash rule a soft preference where a deliberate one is fine, and a
hard-deny hook for it was tried and removed once before — but it is a de-escalation of a working
guard, not the removal of a dead one. **This is a general caveat on every `fired? = no` cell above:
squashed slices hide in-slice catches.**

The rule survives as guidance in `.claude/skills/temari/SKILL.md`, rewritten from a ban into a
preference. `TemariPersona`'s prompt still instructs the *model* not to emit em-dashes; that is
runtime behaviour, untouched by this cut.

**`check-indonesian.php` is not left with nothing.** The premise for cutting it was that its
`ALLOWED` list is entirely legacy redirect URLs that `W1` deletes anyway. Verified: that is true of
six of the ten entries, and those are now gone. Four remain, and none is a redirect:

| word | what it still covers |
|---|---|
| `kartu` | Kartu components, `ShareKartuData`, the **persisted** `AnalysisType` value `briefing_featured_kartu_voice`, the `featuredKartuVoice` Inertia prop, and the `AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT` env var — needs a data migration and a prod env change |
| `aku` | the persisted `AnalysisType` value `aku_profile_voice` and its narrator/job — same shape |
| `rute` | the `rute` share-card layout token, shared between `RunCardImageRenderer` and the client `Layout` union |
| `angin` | a regression test asserting the card no longer says "angin" — the word must stay for the assertion to mean anything |

Plus five comment-quote sites under `pengaturan`/`kalender`/`profil`/`rekor`. So the guard still
polices three live data-migration pockets carrying Indonesian into persisted values and prod
config. **Recommendation: keep it.** Its real problem was friction, and that is fixed below. Revisit
when the Kartu/aku data migration lands and the last three pockets close — at that point the guard
genuinely does protect nothing, and cutting it is clean.

---

## What changed

### 1. One definition of done

`composer check` now runs exactly what CI runs, in fail-fast order. Nine checks that CI ran and
`composer check` did not are now in it: `check-doc-citations.php`, `format:check`, `lint`,
`check:palette`, `test:coverage` (was `test`), `build`, `check:chunks`, and the two structure gates
via `pest --parallel`. [plan/README.md](../README.md) §9 is rewritten to say "run `composer check`"
and nothing else, with the narrow-iteration commands kept separately as an explicitly *non*-gate.

One residual, documented rather than papered over: **CI gates PHP coverage at `--min=95` on every
PR, and there is no coverage driver in the Sail image.** That single gate is unreproducible locally
and is the only way a green `composer check` can still meet a red CI.

### 2. `check-indonesian.php` no longer walks gitignored trees

`SCAN_DIRS` includes `resources`, and the walk was a plain `RecursiveDirectoryIterator`, so it read
whatever was gitignored underneath — in a checkout where the prototype's dependencies are installed,
that is `resources/brand/prototype/node_modules`.

Measured: **161 violations, 100% from that tree**, and almost all from one source — `lucide-react`'s
`GeorgianLari` currency icon, which `segment()` splits into `Georgian Lari` so `\blari\b` matches.
A guard that is red for reasons the author cannot act on is a guard everyone learns to skip, which
is the behaviour that produced today's two round-trips.

Fixed at the root: `collectFiles()` prunes dependency and build-output directory names during the
walk, so the fix covers every such tree under `SCAN_DIRS` rather than the one path.

**`git ls-files` was the tidier answer and was tried first. It does not work here.** Composer strips
`GIT_DIR` from the environment it runs scripts in, and a linked worktree's `.git` is a file pointing
outside the container's mount — so the guard returned *zero files* under `composer check` in exactly
the worktrees `PS1`-`PS11` will run in. It passed under `sail php` and failed under `sail composer
check`, which is the sort of difference that only shows up if you run the real command. Recorded
here because it is a trap for anyone who reaches for `git ls-files` in a guard later.

`vendor` is deliberately **not** in the prune list: `resources/views/vendor/pulse/` is a tracked,
published Blade template the guard has always read, and no ignored `vendor` tree lives under
`SCAN_DIRS`. Pruning it would have cost real coverage to catch nothing — caught by checking, not by
reasoning.

Coverage is unchanged and measured rather than asserted: with the offending tree restored on disk,
the walked set and the tracked set are the same **1,479 files, zero difference in either
direction**. Zero-files stays a hard error, so a broken glob still fails loudly instead of reporting
a clean sweep over nothing.

`check-doc-citations.php` (scans `docs/`) and `check-see-references.php` (scans `app`, `database`)
were checked for the same defect and do not have it — no gitignored tree lives under those roots.

### 3. `docs-citation-guard` → `repo-guards`

The job ran two guards, not one, and gates all five downstream jobs. Renamed, with a comment saying
what belongs in it (unconditional, fast, needs the whole tree). Safe because branch protection
requires only `ci-gate` — verified against the API, not assumed.

### 4. The path-filter gap — the brief's version was wrong, the bug is real

**Correction: `build-prod-image`'s filter *does* fire on `.github/workflows/ci.yml`.**
`^\.github/workflows/ci\.yml$` is present in all three filters, `docker` included. Verified by
running the filter regexes against the literal paths.

The real gap is one directory over: **`.github/actions/**` matched nothing.** `setup-php`,
`setup-node` and `show-runner-resources` are what every suite boots on — edit one alone (a Node
version bump, a broken cache key) and no job runs at all. Fixed by widening both the backend and
frontend filters to `^\.github/(workflows/ci\.yml|actions/)`. Not added to `docker`: no composite
action is used by `build`.

### 5. The nine legacy redirects, deleted

User-ruled (*"i dont really need a perma redirect"*), pulled forward out of `W1`, and the reason it
lands here: six of `check-indonesian.php`'s ten `ALLOWED` entries existed only for these URLs.

**Correction: five of the nine were already broken, not four.** Verified with
`route:list --method=GET`: `/activities`, `/calendar` and `/account` have no `GET` route, so
`/catatan`, `/runs`, `/progress`, `/kalender` **and `/akun`** had all been 301-ing into a 404 since
`S7`. The remaining four (`/pengaturan`, `/profil`, `/rekor`, `/runs/{activity}`) pointed at live
routes. `/runs/{activity}` is the only one with genuine deep-link value — a shared run URL — and
went with the rest per the ruling.

---

## Files touched

Guards and gates: `scripts/check-indonesian.php`, `.github/workflows/ci.yml`, `composer.json`,
`tests/Unit/Architecture/NoEmDashInPromptsTest.php` (deleted).

Redirect deletion: `routes/web.php`, `tests/Feature/Runs/RunControllerTest.php`,
`tests/Feature/Http/Controllers/SettingsControllerTest.php`,
`tests/Feature/Http/Controllers/TrendsControllerTest.php`, `docs/features/records.md`,
`docs/features/settings.md`, `docs/features/profile.md`.

Docs: `plan/README.md` (§3, §5, §9), `plan/ia.md`, `plan/slices/25-W1-ia-cutover.md`, this file,
`CLAUDE.md`, `.claude/skills/temari/SKILL.md`.

## Blockers

None. Deliberately touches no screen code, so it does not collide with `PS1`-`PS11`.

## Acceptance criteria

- [x] Every guard in the repo has a verdict row with evidence — 22 guard-shaped things found, not
      the 12 the brief listed (the extra ten: `check-raw-palette.test.ts`, `cssIntegrity.test.ts`,
      `build-tokens-dark.test.ts`, the enum-freshness pair, `secret-scan`, `nightly-audit.yml`, the
      three git hooks, `ci-gate`).
- [x] `composer check` runs exactly what CI runs; the one thing it cannot (PHP coverage) is named.
- [x] §9 is rewritten to a single command, with narrow-iteration commands marked as not-the-gate.
- [x] `check-indonesian.php` no longer walks dependency trees, with coverage proven identical
      (1,479 = 1,479 files, zero difference either way) rather than asserted.
- [x] No guard removed or weakened except `NoEmDashInPromptsTest`, which the user ruled on.
- [x] `AppLayoutAssetsExistTest`'s missing group tag reported, **not** silently fixed.
- [x] Both the old and the new definition of done run green (see Verification notes).

## Coverage delta

n/a for the frontend — no `resources/js` source changed. Backend: three redirect tests deleted
along with the routes they covered, and one test's assertion narrowed; no production code path lost
coverage, since the deleted routes no longer exist.

## Verification notes

Since this slice edits the ladder itself, both definitions were run and agreed. The old §9 ladder
and the new `composer check` were each run in full from this worktree (slot 1, ports 7011/7012).

The `check-indonesian.php` fix was verified against the failure, not just the fix: the offending
`resources/brand/prototype/node_modules` tree was copied into this worktree, the guard reproduced
all 161 violations, the fix was applied, and the guard went green **with the tree still on disk**.

`composer check` was then run end to end and reached `check:chunks` green. That run is what caught
the `git ls-files` regression above — the guard passed standalone and failed under composer, so
"run the guard" and "run the gate" are not the same test. Full PHP suite 3,638 passed; frontend
coverage 97.15% statements / 96.78% functions / 97.54% lines, comfortably over the 95% gate.

## Open questions

1. **`AppLayoutAssetsExistTest` has no `->group('structure')`.** Both its `it()` blocks are
   untagged, so `pest --group=structure` never runs it — it only executes in the full suite. This
   looks like an oversight (every sibling in `tests/Unit/Architecture/` is tagged) rather than a
   decision. Adding the tag strictly widens what the fast gate covers and changes no assertion, but
   it is a guard-registration change, so it is left for the user to rule on rather than taken here.

2. **`.githooks/pre-commit` now duplicates `composer check` almost entirely.** It runs full phpstan,
   full tsc and full eslint on *every commit* — the dominant local cost across a twelve-slice
   program with many commits per slice, and all three now run pre-push in `composer check` anyway.
   Its unique value is the main-branch block, gitleaks, and the pint/prettier auto-format. Trimming
   the three full-tree analyses would be the single largest efficiency win available; it is also a
   weakening, so it is a recommendation, not a change.

3. **The `changes` path filter buys nothing for the length of this epic.** `changes` diffs against
   the merge-base with **`main`**, so on a PR into `epic/mobile-ux-port` `changed` is the whole epic
   diff — measured on this branch: **1,147 files "changed" against 1 actually changed**. Every
   filter therefore reports true and every job runs. This fails safe (it over-runs, never
   under-runs) and it is why the `.github/actions/` gap above was masked rather than biting. Making
   it accurate would mean epic PRs start *skipping* jobs mid-program, which trades safety for speed
   — reported, deliberately not acted on, per the slice's no-restructuring boundary.

4. **When the Kartu/`aku`/`rute` data migration lands, re-run the `check-indonesian.php` question.**
   At that point its `ALLOWED` list is comment-quotes only and the guard genuinely protects nothing
   live.
