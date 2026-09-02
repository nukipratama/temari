# W5 — Merge readiness

The last slice on the epic. Deletes the frozen prototype per decision 19, repairs the gate that
`W5` exists to run, and corrects the wave-0 ledger. The original stub is
[../../slices/29-W5-merge-readiness.md](../../slices/29-W5-merge-readiness.md); this supersedes its
scope.

**Ruled by the user: this slice stops at the epic.** It does not open or perform the merge to
`main`. That merge auto-deploys and depends on two host-side actions only the user can take, so it
stays a separate, deliberate step.

## The stub asked the wrong question about the ledger

Its acceptance criteria say to "confirm nothing in `plan/ledger.md` is left at `defer`". **Nothing
is, and nothing ever was** — all eleven rows were ruled `keep`, `restyle` or `cut` on 2026-08-28.

The real problem is the line above the table: **"All verdicts below are final."** The 2026-08-31
re-scope made the prototype a hard source of truth, and `PP3` then reversed three of those verdicts
and narrowed two more:

| row | 2026-08-28 | what shipped | reversed by |
|---|---|---|---|
| Persona mix | restyle | UI cut; `PersonaMixTool` survives as narrator context only | `PP3` (P13), `bdee3e4c` |
| Relative effort | keep, mechanical | display cut; kept as narrator input via `EffortContextTool` | `PP3` (P18), `9b79d819` |
| Dawn-shift | keep, light-ground only | cut entirely; `useDawnShift.ts` is gone | `PP3` (P17), `88911428` |
| Kartu | restyle | narrowed — `components/card/` is 8 files, not 20 | `PP3` (P12/P29) |
| Badge / milestone | restyle | narrowed — unlock toast and modal cut | `PP3` (P14), `40a594b9` |

**Ruled by the user: banner *and* per-row correction.** A dated banner at the top carries the table
above; each affected row is struck through inline so a reader scanning the table cannot misread it.
The rows are not rewritten — the fact that these verdicts were made and then reversed is the most
useful thing this file now records.

The "Coupling this ledger surfaced" section also stood as an open instruction to `W2` and `S10`
("Resolve this when `W2` starts — do not delete blind"). It resolved itself before either ran:
`PP3` cut the whole unlock-celebration surface, so `AccessoryUnlockModal` and `UnlockToast` are
both gone regardless of which feature owned them. Recorded, not left dangling.

## The gate could not run, and the cause was not what the record said

§9 of [../README.md](../README.md) declares `composer check` **the whole gate** — "a green
`composer check` means a green CI". It could not complete. It died at step 13 of 16:

```
{"tool":"pest","raw":["Pest\\Exceptions\\MissingDependency","The feature \"Tia mode\" requires \"git\"."]}
Script vendor/bin/pest --parallel handling the check event returned with error code 1
```

**Every one of the 3604 tests passed.** Pest exits non-zero purely because TIA cannot initialise,
which halts composer before `test:coverage`, `build` and `check:chunks` — so the three steps that
guard the bundle and the 95% frontend threshold had never run inside the gate at all.

`Dockerfile:40` already carried the comment *"git is TIA's changed-file source; Pest hard-fails
`requires git` without it"*, and git 2.54.0 is installed. Instrumenting Pest's five
`MissingDependency` throw sites gave the actual cause on the first run:

```
cmd=['git' 'rev-parse' 'HEAD'] exit=128 err=fatal: detected dubious ownership in repository at '/var/www/html'
```

The repo is bind-mounted, so its files carry the host user's uid while the container runs as
`www-data`, and git refuses a repo it does not own. **Pest reports that with the same message as a
missing binary**, which is exactly why installing git looked like the whole fix.

**Fixed via `GIT_CONFIG_COUNT` / `GIT_CONFIG_KEY_0` / `GIT_CONFIG_VALUE_0` on the app service in
`compose.yaml`, not a `git config --system` line in the Dockerfile.** The Dockerfile version was
written first and could not be verified: `docker compose build` fails in this environment with
`DeadlineExceeded` resolving `docker/dockerfile:1.7` from Docker Hub, twice, so no new image can be
produced right now. Shipping unverifiable infrastructure config is the thing that has caused two
production outages here. The env form needs no rebuild, applies to images already on disk, and was
verified against a **force-recreated** container rather than the one it had been configured on by
hand.

## Prototype deletion

Decision 19's final step. Measured before touching it:

- **51 tracked files, 728K.** The 276M on disk was untracked `node_modules`.
- **Nothing outside `plan/` references it** — no import, alias, test, config, script or `docs/` note.
- The 144 `plan/` citations into the tree stay resolvable: **`prototype-frozen` is on origin** at
  `6f7d4018`, and [../README.md](../README.md) §4 already documents reading it back via
  `git show prototype-frozen:...`.

The orphaned `node_modules` was removed from disk at the user's explicit request, after confirming
zero tracked files remained beneath it. `resources/brand/` is 660K, from 276M.

## Carried from `W4`: `--shadow-panel` stays

**Ruled by the user: keep.** It is declared and unread, but unlike the phase tokens it has no rival
set — its sibling `--radius-panel` is used by two heroes, which currently pair it with `shadow-e1`.
Leaving it costs one line and keeps the pairing available.

## Files touched

`plan/ledger.md`, `compose.yaml`, `Dockerfile` (comment only), `resources/brand/prototype/`
(deleted), plus the tracker rows in `plan/README.md` and [../README.md](../README.md).

Also carries `W4`'s tracker row — the branch ruleset gates direct pushes to the epic, so each
slice's "merged" row rides in the next slice's PR.

## Acceptance criteria

1. `composer check` **completes**, all 16 steps, rather than halting at pest. Proven by running it,
   with the exit code read directly rather than through a pipe.
2. The `safe.directory` fix is verified against a force-recreated container, not the one it was
   applied to by hand.
3. `resources/brand/prototype/` is gone from the index and the working tree, and every guard stays
   green afterwards.
4. `plan/ledger.md` carries the dated banner and per-row strikethroughs, and no longer claims its
   verdicts are final without qualification.
5. A full `browser-review` sweep after `npm run build`, across both grounds.
6. The epic is **not** merged to `main`, and the two host-side actions are restated in the PR body.

## Verification notes

- **`composer check` completes, for the first time.** All 16 steps, exit 0, through
  `test:coverage`, `build` and `check:chunks` — the three that had never run inside the gate.
  Frontend coverage **97.86% lines / 97.24% functions**, against a 95% threshold.
- **The cause was found by instrumenting Pest, not by reading.** Five `MissingDependency` throw
  sites were temporarily patched to report their command and stderr; the first run named
  `git rev-parse HEAD` → `detected dubious ownership`. The vendor patch was reverted immediately.
  Three of the four candidate commands I had guessed at beforehand were fine.
- **The first fix was written in the wrong place and could not be verified.** A
  `RUN git config --system` line in the Dockerfile is the obvious home, but `docker compose build`
  fails here with `DeadlineExceeded` resolving `docker/dockerfile:1.7` from Docker Hub —
  **reproducibly, twice** — so no new image can be produced. Worse, the first build reported
  **exit 0** because the command was piped through `tail`, and the image was still dated
  2026-08-13. Re-run without the pipe, the real exit code was 1. The fix moved to `compose.yaml`,
  where it needs no rebuild, and was then verified against a **force-recreated** container rather
  than the one it had been applied to by hand.
- **The sweep could not do what the skill told it to do.** §9 says "check both grounds", and
  `shoot.mjs` had no way to switch. Added `THEME=light|dark|system`, seeding the `temari-theme`
  key via `addInitScript` so the app's blocking pre-paint script resolves the ground before first
  paint. Proven by measurement, not by looking: `light` → `rgb(241,245,248)`, `dark` →
  `rgb(11,16,23)`, the cream and sky-deep token values.
- **`shoot.mjs` clears prior batches**, so the first dark sweep was destroyed by the light one.
  Both grounds were re-shot and copied aside before inspection. Worth knowing before running two
  sweeps back to back.
- **Zero horizontal overflow across all 26 page/viewport combinations.**
- **Dark ground: no findings.** Light ground: two, both correctly flagged as low or
  needs-confirmation rather than asserted.
  - *Inbox has no top-bar brand mark on desktop* — **by design.** `backTargetFor()` gives pushed
    screens a back chevron instead, and Inbox is not one of the four nav tabs. There is no
    `TopNav` component at all; `MobileTopBar` renders at every width.
  - *The elevation stat label truncates at 390px* — **real, and fixed.** Measured
    `scrollWidth 71 / clientWidth 58`. Its two siblings are already abbreviations (`HR`, `TRIMP`),
    so it became `ELEV`; re-measured at 51/51, 40/40, 32/32, none clipped. `shareCard.ts` keeps
    the full word, drawing to a fixed raster with room for it.
- **One console 404 on `/activities` is a tooling artifact, not an app defect.** That route does
  not exist by design (only `activities/{activity}` and `/history`); `lib.mjs` probes a route's
  base path first to find a detail link, gets a 404, and falls back.
- **A commit had to be split.** `git rm` had already staged the prototype deletion, so the
  slice-doc commit swept 51 deletions in with it. Unwound with a soft reset and re-committed in
  order, since nothing had been pushed.

## Open questions

_None open. Four were put to the user and ruled: fix TIA properly rather than passing `--no-tia`;
banner plus per-row correction on the ledger; stop at the epic; keep `--shadow-panel`._
