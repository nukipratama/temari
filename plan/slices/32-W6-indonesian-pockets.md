# W6 — the persisted Indonesian pockets

**Wave** 3 · **Slot** main checkout · **Blockers** none · **Status** todo

## Goal

Rename the last four Indonesian identifiers in the app. All of them are **persisted in the
database, generated into typed frontend code, or set in the production environment**, which is why
the full-English swap on 2026-08-09 could not simply rename them and why every later sweep left them
alone.

This doc exists because `C1` deleted `scripts/check-indonesian.php`, and that script's `ALLOWED`
list was the only written record that these pockets are outstanding work rather than oversights.
The rule itself now lives in [CLAUDE.md](../../CLAUDE.md) as guidance.

## The four pockets

| pocket | where | why it needs a migration |
|---|---|---|
| `briefing_featured_kartu_voice` | [AnalysisType.php](../../app/Services/AI/AnalysisType.php) `case BriefingFeaturedKartuVoice`; narrator `BriefingFeaturedKartuVoiceNarrator`; job `AnalyzeBriefingFeaturedKartuVoiceJob` | The string is the **stored `analyses.type` value**. Renaming the case without migrating existing rows orphans every historical analysis. |
| `aku_profile_voice` | [AnalysisType.php](../../app/Services/AI/AnalysisType.php) `case AkuProfileVoice`, plus the `AKU_PROFILE_VOICE_SUBJECT_TYPE` constant (`aku_profile_voice_user`); narrator `AkuProfileVoiceNarrator` | Same: stored `type`, **and** a stored `subject_type`. Two columns, not one. |
| `AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT` | [config/azure_openai.php](../../config/azure_openai.php), `.env.example` | Set in the **production `.env`** on the homelab host. Renaming the key without setting the new one first silently falls back to the default deployment. Needs a coordinated env change — see the standing rule that multi-side config lands atomically. |
| `rute` (share-card layout) | [RunCardImageRenderer.php](../../app/Services/Run/Story/RunCardImageRenderer.php) `$layout = 'rute'`; [ShareCardModal.tsx](../../resources/js/components/card/ShareCardModal.tsx) `LAYOUTS` and the client `Layout` union | Shared literal across the PHP renderer and the TS union. Not DB-persisted, but it is carried in user-facing share URLs / stored card selections, so check those before renaming. |

Both `AnalysisType` values are also emitted into
[generated.ts](../../resources/js/types/generated.ts) by `typescript:enums`, so the rename
regenerates that file and any migration must land in the same commit as the regeneration.

## Files touched

`app/Services/AI/AnalysisType.php`, the two narrators and the analyze job, `config/azure_openai.php`,
`.env.example`, `app/Services/Run/Story/RunCardImageRenderer.php`,
`resources/js/components/card/ShareCardModal.tsx`, `resources/js/types/generated.ts`, plus a new
migration under `database/migrations/` and the prod `.env`.

## Blockers

None technically. Sequence it after the parity slices so it is not renaming code those slices are
still rewriting.

## Acceptance criteria

_To be filled when the slice starts._ At minimum: existing `analyses` rows keep resolving after the
rename (both `type` and `subject_type`), the prod env var is set **before** the deploy that reads
it, and `angin` in `RunCardImageRendererTest` is left alone — it is a regression assertion that the
card no longer says "angin", and the word must stay for the test to mean anything.

## Coverage delta

_To be filled when the slice starts._

## Verification notes

_To be filled when the slice starts._

## Open questions

1. Is `rute` carried in any persisted user selection or shared URL, or is it purely in-memory? That
   decides whether this one needs a migration at all or is a straight rename.
2. Worth doing at all? These are internal identifiers with no user-visible surface. The honest
   alternative is to declare them grandfathered and close this slice — the cost is a migration plus
   a prod env change against a purely cosmetic gain.
