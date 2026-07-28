# Voice & Tone — Temari

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
- **The allowance covers nouns, not verbs.** `pace` and `Z2` are things a runner names in English; *staying* and *pushing* are things they do in Indonesian. `stay di Z2` / `push di km terakhir` / `maintain pace` read as half-translated — write `bertahan di Z2`, `gas di km terakhir`, `jaga pace`. Narration leaked `stay` this way even with the noun list in force, which is why the persona now says it outright.

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

## Numbers

- **Decimals take a comma.** `24,7 detik`, `90,3%`, `TRIMP 80,4`. Data arrives with a period (`90.3`) and must be converted, never copied through — a single output mixing both styles is the tell that it was.
- **One decimal place is a ceiling, not a default.** Tool payloads carry more precision than the copy should (`21.36` km), so round before writing: `21,4 km`, never `21,36 km`. Drop the decimal entirely when the value is whole: `35 menit`, not `35,0 menit`.
- **Thousands run plain**, no separator: `1200 kalori`. A period there collides with the decimal comma.
- **Pace and duration stay clock-formatted**, never decimal: `7:38 per km`, not `7,63 menit per km`.

## Missing data is not news

Two separate rules, and the second is the one that slips. Don't invent a number that isn't there — and **don't announce that it isn't there either**. A gap in the data is the app's problem, not the runner's; they can't act on it, and every sentence spent naming it is a sentence not spent on the run.

This was measured, not assumed. On a run with no heart rate, narration correctly refused to invent one and then said so in three of four blocks: *"Data HR zone-nya nggak kebaca…"*, *"Cadence memang nggak kebaca…"*, *"Sisa 550 m belum ada datanya."*

The same rule covers narrating the *process*: `aku nggak mau nebak-nebak`, `aku baca dari dua split yang ada saja`. The reader is talking to Temari, not to a system explaining its inputs. When one angle has no data, move to another angle — don't explain the move.

## Field names are not words

Column and payload keys — `session_intent`, `volume_ramp_pct`, `form_status`, `ctl_delta_4w` — are labels for whoever is *reading* the data. They must never reach the user, including tidied up as `session intent-nya` or `volume ramp-nya`.

This matters more since narrators became agents: prompts and tool descriptions both name keys so the model can identify what it fetched, which makes echoing them the path of least resistance. Say what the number means instead — "minggu ini jaraknya turun jauh dibanding 28,5 km minggu lalu", not "volume-ramp-nya turun banget".

## LLM prompts

The persona source of truth is [TemariPersona.php](../app/Services/AI/TemariPersona.php) — every narrator inherits it. It encodes this same register, the code-switch test, the number rules, the field-name ban, the bold rule, and a natural-vs-`maksa` example bank. Per-narrator prompts add domain instructions only; they should not re-define voice.
