# Reading the output and inspecting

> **Reading screenshots.** A full-page mobile shot is ~1170x2532 real pixels (deviceScaleFactor 3).
> 1. **Read each image at most once.** If you need it again, re-read your own notes, not the file.
> 2. **Give each inspector a disjoint page set** (see Inspect below), so no two read the same files.
> 3. **Cropping for a closer look: crop AND downscale in one step, and write `.jpg`.** Never write a
>    full-resolution intermediate you then read. `sips -Z 900 -s format jpeg -s formatOptions 80 in.jpg
>    --out crop.jpg` (or one PIL call). Ad-hoc `crops/*.png` have historically been the single largest
>    source of oversized reads after the sweep itself.

Each run lands in its own batch dir, keyed by date + execution time:
`storage/app/browser-review/<YYYY-MM-DD>/<HHMMSS>/<viewport>/NN-<page>-{viewport,full}.jpg`. `shoot.mjs`
clears prior batches at the start, so only the latest sweep is on disk, and prints the resolved dir as
`BATCH_DIR=...` on its last line — **capture that and pass it to the inspectors.** The script also
prints any console/`pageerror` per page. The audit prints a human-readable `HORIZ-OVERFLOW=true/false`
line per page per viewport (ignoring intentional `overflow-x-auto` scroll containers and decorative
`pointer-events-none` glow blobs) plus a machine-parseable `AUDIT vp=<viewport> name=<page-slug>
overflow=<true|false>` line for every page — **capture and parse these too**, they gate the Inspect
phase below (`name` matches the `-<name>-full.jpg` slug in `shoot.mjs`'s filenames, so the two scripts'
independent page orderings don't need to line up). The overflow flag is `true` if *either* the
document's `scrollWidth` exceeds the viewport *or* any individual element's box extends past it — the
latter alone still flags a page, since an `overflow-hidden` ancestor can clip a child without growing
`scrollWidth`, which would otherwise hide real off-screen content from the check entirely.

> These PNGs are gitignored (`storage/app/.gitignore` ignores `*`) and your IDE may hide gitignored
> files — they're on disk under `storage/app/browser-review/`, not in a temp dir.

## Inspect (audit-gated)

`audit.mjs` already found horizontal overflow for every page, so reserve visual judgment for what
code cannot check. Per viewport, inspect two page sets: every audit-flagged page, to confirm what is
actually broken so the overflow finding is actionable; and four evenly spaced non-flagged pages, as a
sample for overlapping, clipped or truncated text, wrong nav chrome, off-screen elements, awkward
spacing, and hierarchy problems.

You can do this yourself, one viewport at a time, reading each screenshot once and noting findings
as you go. You may hand it off to subagents instead: at most three concurrent
inspectors, each owning one viewport pair (for example `mobile`+`se`, `laptop`+`desktop`) with both
its flagged and sampled pages, and each reporting findings in text. Either way every finding follows
the evidence contract below.

Treat width-capped content (`PageContainer` / `max-w-page-2xl`), the fixed bottom-nav mid-page
artifact, sparse demo-data grids, and intentional `overflow-x-auto` as designed behavior.

### Verify before reporting — the rate is worse than "some"

A screenshot is a weak source, and the numbers are not hypothetical. One pass produced 20 findings and
roughly a third did not survive checking. A later pass produced **3, and all 3 were wrong**: a fixed
bottom nav read as an element collision, a notification bell read as an empty box, a token-correct
inverted pill read as a wrong-ground bug. Every one cost a round of someone's attention.

Every inspector **must verify before returning a finding**, and the result requires the evidence
so the requirement cannot be quietly skipped. `probe.mjs` makes that one command:

```bash
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/probe.mjs <route> [dark|light] [--click=<text>] [--viewport=<key>] [--shot] '<expression>'
```

`--viewport` takes a `VIEWPORT_DEFS` key (`mobile`, `se`, `tablet`, `laptop`, `desktop`) and defaults to the 390x844 mobile context; an unknown key exits with the valid list.

It logs in, sets the ground, optionally drives one control, evaluates the expression in the page and
prints JSON — `{ result, console, shot }`, where `console` is any console/pageerror messages captured
during the run (always on, a live substitute for a browser devtools console mid-coding) and `shot` is
a saved screenshot path when `--shot` is passed. A claim that content is *missing* is answered by
querying for it; a claim that something is *invisible* is answered by `getComputedStyle`; a claim
about *size* is answered by `getBoundingClientRect`. That is how a flex-shrink bug squeezing a 6px
dot to 0px was confirmed —
invisible in a screenshot, obvious in one call.

Two standing sources of false positives to weigh before reporting at all:

- **Screenshots come from separate logins.** `shoot.mjs` opens a fresh context per viewport, so mobile
  and desktop shots of the "same" page are independent server requests. Any `Analysis`-backed content
  can legitimately differ for reasons unrelated to responsive CSS.
- **"Missing" is a much stronger claim than "small/faint/different."** It is also the claim most often
  wrong, and the one most likely to be acted on without re-checking. It always needs a probe.

Give every inspector the batch dir, its viewport, and the parsed `AUDIT` records, for example:
```json
{
  "dir": "storage/app/browser-review/2026-06-19/143022",
  "viewports": ["mobile", "desktop"],
  "pages": {
    "mobile": [{ "name": "today", "overflow": false }, { "name": "activities-detail", "overflow": true }],
    "desktop": [{ "name": "today", "overflow": false }, { "name": "activities-detail", "overflow": false }]
  }
}
```
Here `dir` is the `BATCH_DIR=` line from `shoot.mjs`, and `pages[viewport]` comes from step 3's
`AUDIT` lines. Each inspector returns `viewport` plus findings containing `page`, `severity`,
`issue`, and `evidence`; `evidence` includes the `probe.mjs` command and result. Merge the results,
discard every unverified claim, and allow an empty findings list. State the batch dir in the final
summary so the screenshots are easy to open.
