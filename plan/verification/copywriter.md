# Verification rubric — copywriter

**How to use**: dispatch a subagent with this file and the slice's diff. It reviews **every
user-visible string** the diff adds or changes — UI chrome, empty states, error messages, button
labels, aria-labels, notification bodies, and any prompt string sent to the LLM.

Source of truth: [../../docs/voice-and-tone.md](../../docs/voice-and-tone.md). Read it first. This
rubric is the checklist, not the doctrine.

---

## 1. Who is speaking

Temari is **a friend who runs** — specifically an experienced-runner friend. Not a coach, not a
cheerleader, not an assistant, and never "an AI". The AI is plumbing; it is soft-sold in product
copy and is never Temari's identity.

- A training partner who **keeps score**, not one who celebrates participation. "you held 5:12 for
  the last 3k" beats "amazing effort!"
- Support is **earned and optional**. Encouragement appears when the run warrants it, not as a
  reflex on every screen. A slice that adds a supportive line to a neutral state is a finding.
- Prescription is allowed **in the numbers** (the plan engine is prescriptive by design), not in
  Temari's voice. Temari reports and observes; the plan tells you what to run.

## 2. Hard rules

- **English only.** No Indonesian. `scripts/check-indonesian.php` runs unconditionally in CI, but it
  is a regex — catch what it misses.
- **No em-dashes** (`—`) in UI copy or in prompt strings. It is an AI tell. This is a strong
  preference, not an absolute ban; a single deliberate one in long-form prose is defensible, a
  sprinkle of them across new UI strings is a finding.
- **No fabricated numbers.** Copy must not state a figure the backend does not actually produce.
- Emoji per the voice doc. Do not introduce new emoji usage on a whim.

## 3. Register

- **Narrated voice** (Temari speaking: briefings, analysis blocks, card captions) is lowercase-start.
- **UI chrome** (nav labels, buttons, section headings, table headers, settings rows) is Title Case
  in the shipped app today.
- **Small mono labels stay uppercase.** Eyebrows and stat-tile captions in caps are deliberate.

> **Open question this rubric carries — flag it, do not resolve it alone.**
> The prototype renders essentially everything lowercase, including the wordmark and button labels.
> That treatment was agreed for the **login mockup specifically** and diverges from
> `voice-and-tone.md` on purpose. Whether it extends to the other ten screens is **undecided**. Any
> slice that applies all-lowercase beyond Login must say so in its slice doc and get an explicit
> ruling — it is not a silent styling choice. Once ruled, the answer goes in this file and in the
> amendments log of [../README.md](../README.md).

## 4. States nobody writes copy for

The port is where these get dropped. For every component the slice touches, check that copy still
exists and still reads correctly for:

- **empty** — no runs yet, no plan yet, no notifications, first-ever session
- **loading** — and whether the skeleton needs words at all
- **failed** — an AI block that exhausted its retries shows an empty state with "Try again"; the copy
  must not imply the content is coming
- **pending vs. paused** — a paused block (AI disabled / unset / breaker tripped) stays honestly
  pending and is re-kicked for free. Copy must not promise a result the system will not produce.
- **degraded** — past the daily cost ceiling, blocks are filled from the rule-based narrator. That
  text ships to users too; it gets the same review as LLM output.

## 5. Prompt strings count

A narrator's system/user prompt is copy. It shapes every sentence users read.

- Same voice rules apply inside the prompt, including the em-dash rule.
- The prompt must not instruct the model to invent, estimate, or soften a number.
- For `B4` specifically (decision 11): plan narration is **voice-only**. If a prompt asks the model
  to produce or adjust a distance, pace, duration or count, that is a finding — the rules own every
  number.

## 6. Consistency

- One term per concept across the whole app. If the diff introduces a second word for something that
  already has one ("session" vs "workout", "streak" vs "run streak"), that is a finding.
- English run terms stay English (tempo, interval, long run, easy, taper, base, build, peak).
- Labels match what the backend enum actually calls the thing, so a user reading the UI and a
  developer reading the DB are talking about the same state.
