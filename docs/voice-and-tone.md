# Voice & Tone — Teman Lari

How copy should sound across the whole product: UI chrome, Temari's narration, and the LLM prompts. Sibling to [design-tokens.md](design-tokens.md). When in doubt, read this before writing user-facing strings.

The one-line goal: **sound like a real Jakarta runner talking to a friend** — relaxed, warm, never `lo/gue`, never textbook, never translated-from-English.

## Register — one casual voice everywhere

Temari (the character) and the chrome around her speak the **same** casual register. There is no "formal mode."

- **Use freely:** `aku` / `kamu`, `udah`, `gak` / `nggak`, `dapet` / `dapetin`, `liat`, `bareng`, `lagi`, `banget` (sparingly), `nyambung`, `dipake`, `kelar`, `kebuka`, `kepoin`, `beruntun`. These read as natural casual Indonesian, not slang-overload.
- **Particles** (`ya`, `kok`, `sih`, `deh`, `nih`) are fine tipis-tipis (lightly). Temari's voice bubbles can carry them; chrome labels are short so they naturally carry fewer.
- **Bright line — never cross:** `lo` / `gue` / `elo`, hard slang (`anjir`, `njir`, …), ALL-CAPS shouting, emoji spam. Santai ≠ kasar.
- Short-to-medium sentences, conversation rhythm, not textbook paragraphs.

Note: casual vernacular like `kebuka` / `kepoin` / `nyambung` is **on-voice and should stay**. The things to fix are calques and jargon (below), not the slang.

## The code-switch test (English words)

For any English word, ask: **"would a Jakarta runner actually say this word in English while chatting?"**

- **Common running terms → keep English.** `pace, split, negative split, tempo, easy run, long run, recovery, cadence, warmup, cooldown, PR, HR, splits, interval, threshold, gear, workout, cool down, warm up`. This is how runners talk. Card badges like `Negative Split` / `Long Slow Distance` count here.
- **Loanwords people genuinely say → keep.** `highlight, sync, share, rekap, progress, streak, vibe, mood, fit, mode, podium`. Forcing `sorotan` / `sinkronkan` / `bagikan` / `mode` sounds like a manual. (`★ Highlight minggu ini`, not `Sorotan`.)
- **Generic UI / everyday English → translate.** It reads as lazy translation otherwise: `earn → dapetin`, `tap → ketuk`, `Quality day! → Lari berkualitas!`, `continue → lanjut`, `back → kembali`, `save → simpan`, `next → berikutnya`.
- **Internal consistency beats translation.** Localize a word everywhere or nowhere. Rarity is shown in Indonesian, so there is no `Epic+`; `hr` appears as `HR` consistently, never translated to `DNJ`.

## Beginner-accessibility tier (obscure jargon)

Common running words everyone gets stay English. But **obscure training-science jargon a newbie won't understand must be renamed or always explained**:

- Renamed: **`Form` (TSB / fitness-fatigue balance) → `Kesiapan`** ("readiness" — matches how the app uses it). A beginner reads "Form" as posture; "Kesiapan" they get instantly.
- Explain-on-tap (don't necessarily rename): `TRIMP`, `decoupling`, `CTL/ATL`, `threshold`. Pair each with the existing `<MetricExplainer metricKey="…" />` ([metricGlossary.ts](../resources/js/lib/metricGlossary.ts)) so it's one tap to learn.

## Calque blacklist (delete on sight)

Sentence shapes that ape English word order and read "translated":

- Split editorial headlines whose *content* is a calque (`"Yang Temari kasih kamu" / "semuanya."` → `"Semua kartu kamu, dari Temari."`). The two-line italic-accent format is fine; the calqued content was the problem.
- `X tumbuh seiring Y` (`"Koleksi tumbuh seiring larimu."` → `"Makin sering lari, makin banyak kartunya."`).
- `Yang [verb] [obj]` noun phrases (`"Yang serupa dari koleksi"` → `"Kartu mirip di koleksimu"`).
- `ada [N]-nya` (`"belum ada kartunya"` → `"belum ada kartu di sini"`).
- Ungrammatical compressions (`"Ini layak kartu."` → `"Ini pantas dapet kartu."`).
- **When both the English and the literal Indonesian feel off, rephrase the whole line** instead of translating word-for-word.

## Emphasis: bold

`**bold**` is allowed — in static UI (`<strong>` / `font-bold`) and in LLM narration — to highlight **one** key point per block (a word or short phrase, never a whole sentence). Don't stack it with `<GradientText>` (which owns number emphasis). No other markdown (no italic, headings, bullets, code, numbered lists).

LLM narration renders `**…**` via [`renderBold`](../resources/js/lib/richText.tsx); any surface that renders Temari's text routes through it (`AnalysisStatus` default + every `renderContent` caller), so emphasis lands instead of showing literal asterisks.

## Card rarity ladder

Tiers escalate as **felt specialness**, in plain Indonesian (no borrowed loot-game `Epik`):

`Biasa · Berkesan · Langka · Istimewa · Legendaris`

Labels only — the `Rarity` enum cases (`common…legendary`) and `rarity-*` color tokens are unchanged. Source of truth: [Rarity.php](../app/Enums/Rarity.php) and [runcard.ts](../resources/js/lib/runcard.ts); keep them in sync.

## Before/after reference

| Where | Before (translated/jargon) | After (on-voice) |
|---|---|---|
| Kartu header | `Yang Temari kasih kamu / semuanya.` | `Semua kartu kamu, dari Temari.` |
| Kartu eyebrow | `… · N Epic+` | `… · N terbaik` |
| Featured panel | `★ Sorotan minggu ini` | `★ Highlight minggu ini` |
| Empty state | `Filter ini belum ada kartunya.` | `Belum ada kartu di sini.` |
| KartuDetail | `Yang serupa dari koleksi` | `Kartu mirip di koleksimu` |
| Card reveal | `Ini layak kartu.` / `tap untuk lanjut` | `Ini pantas dapet kartu.` / `ketuk untuk lanjut` |
| Empty runs | `kartu yang bisa kamu earn` | `kartu yang bisa kamu dapetin` |
| KPI | `Form` | `Kesiapan` |
| Milestone | `… Quality day!` | `… Lari berkualitas!` |
| Special move | `Mode Metronom` | `Metronom` |
| Rarity (epic) | `Epik` | `Istimewa` |
| Profile page | `Aktivitas terbaru` | `Lari terbaru` |
| Streak nudge (Telegram) | `Your streak is at risk` | `Streak lari {n} minggu kamu belum ada progres minggu ini` |
| Past you match | `You were faster/slower` | `Dulu kamu {lebih cepat/lebih lambat}` |

### Common calque patterns to avoid

| Calqued phrase | Natural alternative | Why |
|---|---|---|
| `tumbuh seiring larimu` | `makin sering lari, makin banyak kartunya` | "tumbuh seiring" is formal-paper, not chat |
| `Yang [verb] [obj]` (e.g. `yang serupa dari koleksi`) | `kartu mirip di koleksimu` | "Yang … dari" is English "the ones … from" structure |
| `ada [N]-nya` (e.g. `belum ada kartunya`) | `belum ada kartu di sini` | "-nya" possessive is a calqued "its" |
| `Ini layak kartu.` | `Ini pantas dapet kartu.` | "layak" without a verb is English compression |
| `Tidak ada…` vs `Gak ada…` | `Gak ada…` | Formal negation where casual is natural |

## LLM prompts

The persona source of truth is [TemariPersona.php](../app/Services/AI/TemariPersona.php) — every narrator inherits it. It encodes this same register, the code-switch test, the bold rule, and a natural-vs-`maksa` example bank. Per-narrator prompts add domain instructions only; they should not re-define voice.
