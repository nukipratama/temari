# W6 — the Indonesian pockets

Closes the last Indonesian identifiers left by the full-English swap of 2026-08-09. The original
stub is [../../slices/32-W6-indonesian-pockets.md](../../slices/32-W6-indonesian-pockets.md); this
document supersedes its scope, which was wrong in four ways.

## The stub named four pockets. There are eight categories.

| the stub said | actually |
|---|---|
| `briefing_featured_kartu_voice` needs a data migration | **Gone.** `W2` deleted the whole pipeline, so this pocket closed itself. Two references survive as W2 leftovers (below). |
| `AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT` is the one env pocket | **There are two.** `AZURE_OPENAI_AKU_PROFILE_VOICE_DEPLOYMENT` is also deployed config, also Indonesian, and the stub never mentions it. It matters more than the one it did name — see "The env var that can fail silently". |
| `rute` may be persisted; that decides whether it needs a migration | **It is not.** Layout is React state (`useState<Layout>('kartu')`) plus a PHP default parameter. No column, no share-URL param, no request accepts it. Straight rename. |
| `rute` is the only share-card layout token | **`kartu` is one too**, and it is also the product noun across 197 code references and 12 docs. **Ruled by the user 2026-09-02: it is a pocket, not a product name — rename it to Card.** |

Two further categories the stub does not cover, **both ruled in by the user**: Indonesian
**test-fixture strings**, and **stale comments** quoting UI copy that has been English for weeks.

## Why no data migration

`W1` recorded the user's ruling that the epic's merge to `main` is followed by a `migrate:fresh`.
That removes the only real difficulty here: `aku_profile_voice` is a stored `analyses.type` **and**
a stored `subject_type`, and renaming it in place would orphan every historical row. With no data
surviving the merge, both are ordinary renames.

**This makes deploy order load-bearing.** Between merging the epic and running `migrate:fresh`,
stored rows carry values the enum no longer has, and `AnalysisType::from()` throws on them. The
rename must not reach production without the fresh migrate in the same window.

## The env var that can fail silently

`config/azure_openai.php` keys each narrator's deployment override off the `AnalysisType` value, so
renaming the case renames the config key, which renames the env var:

```
AZURE_OPENAI_AKU_PROFILE_VOICE_DEPLOYMENT  →  AZURE_OPENAI_PROFILE_VOICE_DEPLOYMENT
```

Every one of these falls back to `env('AZURE_OPENAI_DEPLOYMENT')` when unset. **There is no error
and no log line.** If the old variable is set on the host and the new one is not, the Profile voice
silently stops using its own deployment and starts using the primary — which, given this app routes
a premium model to a short list of narrators and a cheaper one everywhere else, is a quiet quality
regression rather than an outage.

So: the new variable is set on the host **before** the deploy that reads it, and the old one is
removed after. Same standing rule that multi-side config lands atomically. The value to copy across
is whatever the host currently has; this slice does not read it.

`AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT` has no such risk — `W2` deleted its config
line, so nothing reads it at all. It is removed from `.env.example` here and can be dropped from the
host whenever.

## Naming

`Card` alone would collide with shadcn's `card` primitive and read ambiguously beside the `RunCard`
model, so the component takes the domain object's own name. Everything else is the plain English
word.

| current | new |
|---|---|
| `AnalysisType::AkuProfileVoice` / `aku_profile_voice` | `ProfileVoice` / `profile_voice` |
| `AKU_PROFILE_VOICE_SUBJECT_TYPE` / `aku_profile_voice_user` | `PROFILE_VOICE_SUBJECT_TYPE` / `profile_voice_user` |
| `AkuProfileVoiceNarrator`, `AnalyzeAkuProfileVoiceJob` | `ProfileVoiceNarrator`, `AnalyzeProfileVoiceJob` |
| `Layout = 'kartu' \| 'rute' \| 'stats'` | `'card' \| 'route' \| 'stats'` |
| `KartuMini` / `KartuMiniProps` | `RunCardMini` / `RunCardMiniProps` |
| `ShareKartuData` | `ShareCardData` |
| `KartuPropsOptions`, `kartuPropsFromDetail`, `kartuProps` | `CardPropsOptions`, `cardPropsFromDetail`, `cardProps` |
| `KartuTeaser`, `KartuStats` | `CardTeaser`, `CardStats` |
| `kartuMiddle`, `ruteMiddle` (PHP) | `cardMiddle`, `routeMiddle` |
| `.kartu-glow` (app.css) | `.card-glow` |
| bare `kartu` as a variable or object key | `card` |
| "Kartu" in prose and docs | "card" / "run card" |

**Not renamed — and the record was wrong about how many.** CLAUDE.md said `angin` was the single
exception. There are **three**, all the same shape: an assertion that retired Indonesian copy is
*absent*, where deleting the word silently guts the test.

| site | assertion |
|---|---|
| `RunCardImageRendererTest:132` | `->not->toContain('angin')` |
| `RunCardImageRendererTest:144` | `->not->toContain('tidak tersedia')` |
| `NarratorsCoverageTest:1430-1431` | `->not->toContain('maksimal 90 kata')` / `'maksimal 100 kata'` |

Plus `2026_08_10_100000_rename_run_card_badges_to_english_slugs.php`, which names the old slugs
because it *is* the map from them — the deleted guard skipped that file for exactly this reason.
CLAUDE.md is corrected.

## Folded in: two `W2` leftovers

Both mine, both found while scoping this: a dangling `.env.example` line for the deleted
featured-kartu deployment override, and a vacuous test case (below).

## Two vacuous tests found on the way

Both passed for the wrong reason, and both were proven vacuous rather than assumed:

1. **`TriggerAnalysisRequestTest`** still listed `briefing_featured_kartu_voice` among "the types
   keyed by a discriminator" after `W2` deleted the type. Substituting `'utterly_not_a_type'` passed
   identically — the case was asserting that an unknown *type* is rejected, which "rejects an
   unknown type" already covers. Removed.
2. **`AiUsage.test.tsx`** asserted that no button named `/Pulihkan semua/` renders when nothing is
   stuck. The app's button is "Recover all" and always has been in English, so the query could never
   match and the assertion could never fail. Renamed to the real label, then **proven** by inverting
   it: it now fails when the button is absent, where before it passed either way.

## Acceptance criteria

1. No Indonesian identifier, string literal or comment survives outside the three recorded
   regression assertions and the badge-rename migration, verified by a repo-wide sweep using the
   86-word list recovered from the deleted `check-indonesian.php` rather than a hand-written one.
2. `AnalysisType` regenerates into `types/generated.ts` in the same commit as the rename.
3. The renamed env var is documented in `.env.example` and called out in the PR body with the
   before/after and the silent-fallback consequence.
4. The vacuous test case is gone, and the behaviour it appeared to cover is still covered.
5. Full PHP suite, frontend suite, `check:palette`, `check:chunks`, `check-doc-citations.php` and
   `check-see-references.php` green, run directly.

## Verification notes

- **The sweep used the deleted guard's own word list, not mine.** `C1` removed
  `scripts/check-indonesian.php`; recovering it from git yielded 86 curated words, an `ALLOWED`
  ledger and a `NARRATION_WORDS_IN_TESTS` allowance. My hand-written list had missed `kamu`,
  `banget` and `keren` outright, so the recovered one is what the acceptance sweep runs on.
- **The recovered `ALLOWED` ledger is itself a record of what has since changed**: it excused
  `kartu`, `aku` and `rute` as outstanding pockets (this slice closes all three) and six legacy
  redirect paths (`pengaturan`, `profil`, `kalender`, `catatan`, `akun`, `rekor`) that `C1` deleted.
  Its `NARRATION_WORDS_IN_TESTS` list is why the test fixtures were never flagged — the guard
  tolerated Indonesian narration vocabulary inside tests by design, and its own anti-decay rule
  would now report that list as stale.
- **The typechecker caught the one real collision** in the `rute` → `route` rename: `shareCard.ts`
  already had a `drawRoute()` polyline primitive, so the template renderer would have become a
  duplicate. It is `drawRouteHero` now, matching its `drawHero` / `drawStats` siblings.
- **Production special-move names were checked before touching their fixtures** — `SpecialMoves.php`
  emits only English (`Personal Best`, `Quick Feet`, `Steady Tempo`), so `Marathon Perdana` and
  friends were stale test data rather than a live pocket. The fixtures now use real production
  values, which fixes their realism as well as their language.
- **An apostrophe bug was caught by the parser, not by review**: the first fixture pass wrote
  `'Temari's take'` into single-quoted PHP. Reverted and redone with apostrophe-free replacements;
  every changed PHP file then passed `php -l` before the suite ran.
- Full PHP suite **3604 green**, frontend **1825 green** across 214 files, `check:palette`,
  `check:chunks`, `check-doc-citations.php` and `check-see-references.php` green, run directly.

## Open questions

1. **The guard stays deleted.** Restoring `check-indonesian.php` was put to the user and **declined**
   — `C1`'s call that this is guidance rather than a gate stands. Recorded here because the recovered
   script is a good one, and because this slice is the moment its exception list would have been
   smallest: three regression assertions and nothing else.

2. **A stale docblock left in an applied migration.**
   `2026_05_23_100004_add_pending_reveal_card_id_to_users.php:14` still describes
   `POST /api/kartu/{card}/seen`, a route `PP3` deleted, on a column `W2` dropped. Left as history,
   consistent with keeping the badge-rename migration's Indonesian slugs.
