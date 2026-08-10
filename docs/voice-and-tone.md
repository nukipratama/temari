# Voice & Tone — Temari

How copy should sound across the whole product: UI chrome, Temari's narration, and the LLM prompts. Sibling to [design-tokens.md](design-tokens.md). When in doubt, read this before writing user-facing strings.

The one-line goal: **sound like a friend who runs with you** — warm, casual, never textbook, never clinical.

## Register — one casual voice everywhere

Temari (the character) and the chrome around her speak the **same** casual register. There is no "formal mode."

- **Contractions are welcome, even encouraged:** `you're`, `it's`, `don't`, `gonna`, `kinda`, `gotta`. Use them the way people actually talk.
- **Light conversational fillers** are fine in small doses, to keep it loose: `yeah`, `honestly`, `though`, `look`. Don't sprinkle one into every sentence.
- **Bright line — never cross:** profanity or crude slang, ALL-CAPS shouting, emoji spam. Casual doesn't mean disrespectful.
- Short-to-medium sentences, conversational rhythm, not textbook paragraphs.

## Vocabulary policy

- **Common running terms stay plain English, used as-is** — that's how runners already talk: `pace, split, negative split, tempo, easy run, long run, fartlek, interval, recovery, cadence, warmup, cooldown, PR, HR, splits, lap, laps`.
- **Loanwords runners already say naturally** can be used as-is: `highlight, sync, share`.
- **What's allowed to stay a distinct term is the noun, not the verb around it.** The rest of the sentence stays plain English.
  - Wrong: "you were mostly camping in Z2." / "try to send it on the last km." / "keep maintaining the pace."
  - Right: "you were mostly in Z2." / "try to push it on the last km." / "keep the pace steady."
- **Mood terms (Daybreak):** `blazing` (PR / hard-earned win), `easy` (easy / light aerobic), `wobbly` (HR drift / rough day), `gassed` (high strain / wiped out), `overloaded` (overreaching / monotony), `chill` (rest / quiet day).
- **Daily vibe terms**, used as-is: `Bouncy, Steady, Worn Down, Cooked, Fresh, Stretched Thin, Pumped, Hibernating`.

## Jargon-accessibility tier

Common running words everyone gets stay plain English and never get explained. But **jargon-heavy technical terms should never be dropped raw** — always pair them with a short explanation the first time they show up:

- Technical terms a casual reader might not know (`TRIMP`, `decoupling`, `CTL`, `ATL`, `threshold`): fine to use, but always paired with a plain-language gloss. Example: "decoupling +12%, meaning your heart rate crept up while pace held steady, a sign your base isn't quite there yet."
- Training-load jargon (`load`, `baseline`, `form`, `monotony`, `strain`, `readiness`) reads better translated into plain words than dropped raw: "your training load", "what's normal for you", "how you're holding up", "how varied your training's been", "the strain you're carrying", "how ready you are". If you do use the technical term anyway, pair it with a short explanation like the rule above.

## Emphasis: bold

`**bold**` is allowed — in static UI (`<strong>` / `font-bold`) and in LLM narration — to highlight **one** key point per block (a word or short phrase, never a whole sentence). Max once per output, don't overuse it. When in doubt, skip it. Don't stack it with `<GradientText>` (which owns number emphasis). No other markdown (no italic, headings, bullets, code, numbered lists).

LLM narration renders `**…**` via [`renderBold`](../resources/js/lib/richText.tsx); any surface that renders Temari's text routes through it (`AnalysisStatus` default + every `renderContent` caller), so emphasis lands instead of showing literal asterisks.

## Numbers

- **Decimals take a period.** `24.7 seconds`, `90.3%`, `TRIMP 80.4`. Data already arrives formatted this way — keep it as-is, never convert it.
- **One decimal place is a ceiling, not a default.** Tool payloads carry more precision than the copy should (`21.36` km), so round before writing: `21.4 km`, never `21.36 km`. Drop the decimal entirely when the value is whole: `35 minutes`, not `35.0 minutes`.
- **Thousands run plain**, no separator: `1200 calories`. A comma there would collide with the decimal point of other locales' copy and add nothing.
- **Pace and duration stay clock-formatted**, never decimal: `7:38 per km`, not `7.63 minutes per km`.

**Where this is enforced.** Backend copy formats through [DecimalFormatter](../app/Services/Run/Metrics/DecimalFormatter.php) — `decimal()` for a fixed precision, `trimmed()` when a whole value should shed its trailing `.0`. Distances go through [DistanceFormatter](../app/Services/Run/Metrics/DistanceFormatter.php): `kmString()` is the one-decimal copy form, `km()` returns the fuller-precision float for payloads and prompt arguments. Every one of those, copy and payload alike, now uses the same period convention — see the `# Numbers` block in [TemariPersona.php](../app/Services/AI/TemariPersona.php) for the model-facing wording of the same rule.

## Missing data is not news

Two separate rules, and the second is the one that slips. Don't invent a number that isn't there — and **don't announce that it isn't there either**. A gap in the data is the app's problem, not the runner's; they can't act on it, and every sentence spent naming it is a sentence not spent on the run.

The failure mode to watch for: narration correctly refuses to invent a missing number, then undermines it by announcing the gap anyway — *"HR zone data isn't available"*, *"Cadence isn't showing up"*, *"No data for the last 550m."* Both TemariPersona.php's rules and this doc's rule exist because that slip is easy to make and easy to miss in review.

The same rule covers narrating the *process*: "I don't want to guess", "I'm just going off the two splits I have". The reader is talking to Temari, not to a system explaining its inputs. When one angle has no data, move to another angle — don't explain the move.

## Field names are not words

Column and payload keys — `session_intent`, `volume_ramp_pct`, `form_status`, `ctl_delta_4w` — are labels for whoever is *reading* the data. They must never reach the user, including tidied up as "your session intent" or "your volume ramp".

This matters more since narrators became agents: prompts and tool descriptions both name keys so the model can identify what it fetched, which makes echoing them the path of least resistance. Say what the number means instead — "your distance this week dropped a lot compared to the 28.5 km last week", not "your volume ramp dropped hard".

## LLM prompts

The persona source of truth is [TemariPersona.php](../app/Services/AI/TemariPersona.php) — every narrator inherits it. It encodes this same register, the vocabulary policy, the number rules, the field-name ban, the bold rule, and a natural-vs-forced example bank. Per-narrator prompts add domain instructions only; they should not re-define voice.
