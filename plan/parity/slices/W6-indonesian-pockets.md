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

**Not renamed**: `angin` in `RunCardImageRendererTest`. It is the assertion that the card no longer
says "angin", and the word has to stay for the test to mean anything. This is recorded in CLAUDE.md
and holds.

## Folded in: two `W2` leftovers

Both mine, both found while scoping this.

1. **A vacuous test case.** `TriggerAnalysisRequestTest`'s "requires the discriminator on the types
   keyed by one" still lists `'briefing_featured_kartu_voice'`. The type no longer exists, so the
   case passes because the *type* is rejected, not because a discriminator is required — proven by
   substituting `'utterly_not_a_type'`, which passes identically. It asserts nothing, and the
   behaviour it looks like it covers is already covered by "rejects an unknown type".
2. **A dangling line in `.env.example`** for the deleted featured-kartu deployment override.

## Acceptance criteria

1. No Indonesian identifier, string literal or comment survives outside the recorded exception
   (`angin`), verified by a repo-wide sweep for the word list this slice was scoped from.
2. `AnalysisType` regenerates into `types/generated.ts` in the same commit as the rename.
3. The renamed env var is documented in `.env.example` and called out in the PR body with the
   before/after and the silent-fallback consequence.
4. The vacuous test case is gone, and the behaviour it appeared to cover is still covered.
5. Full PHP suite, frontend suite, `check:palette`, `check:chunks`, `check-doc-citations.php` and
   `check-see-references.php` green, run directly.

## Verification notes

_To be filled as the slice lands._

## Open questions

_To be filled as the slice lands._
