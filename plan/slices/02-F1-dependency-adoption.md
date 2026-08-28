# F1 — Dependency adoption

**Wave** 1 · **Slot** main checkout · **Blockers** `P0`, `L0` · **Status** merged

## Goal

Add `@base-ui/react`, `lucide-react`, `clsx` to `dependencies` and `shadcn` to `devDependencies` (it
is a CLI; the prototype has it in `dependencies`, which is an `npm audit --omit=dev` surface). Add a
`base-ui` group to `vite.config.ts`'s `codeSplitting.groups` at priority 10 **before** anything
imports it — `@base-ui/react` drags `@floating-ui`, and `check:chunks` fails on the dependency-add PR
alone otherwise (see R6). Vendor `components.json` for the CLI. Zero component changes.

## Files touched

`package.json`, `package-lock.json`, `vite.config.ts`, `components.json` (new).

## Blockers

`P0`, `L0`.

## Acceptance criteria

1. `@base-ui/react`, `lucide-react`, `clsx` land in `dependencies`; `shadcn` in `devDependencies`.
2. A `base-ui` chunk group exists in `vite.config.ts` before anything imports Base UI.
3. `components.json` vendored, `aliases.utils` pointed at the existing `@/lib/cn` (not a new
   `lib/utils.ts`) so future `shadcn add` invocations generate imports against the app's actual
   `cn()` — which already carries the `tailwind-merge` `font-size` group extensions — rather than a
   duplicate file.
4. Zero component changes: `git diff --stat` outside `package.json`/`package-lock.json`/
   `vite.config.ts`/`components.json` is empty.
5. Full ladder green (see Verification notes) — including a build that shows **no `base-ui` chunk
   emitted**, proving nothing imports it yet.

## Coverage delta

n/a — no test-bearing code changed.

## Verification notes

**Deviation from the original scope, logged here rather than silently expanded**: the prototype's
`src/index.css` does `@import "tw-animate-css"` and its `toggle-group.tsx` primitive uses the
`data-[state=...]` animate utilities it provides — confirmed by reading both files at the frozen SHA.
Decision 3 named four packages; `tw-animate-css` is a fifth, necessary one, added to `dependencies`
here. Not speculative — it is demonstrably used by code this program is porting, and `F3`'s
`toggle-group` swap would break without it.

Verified `shadcn` in `devDependencies` (not `dependencies`, contra the prototype) is still safe: its
`@import "shadcn/tailwind.css"` resolves at Tailwind-Vite build time, and `Dockerfile:109` runs
`npm ci` with no `--omit=dev`, so devDependencies are present for every build stage.

Ladder run 2026-08-28, all green:

```
npx vitest run resources/js/test/structure.test.ts   # 2 passed
npm run typecheck                                     # clean
npm run build                                          # no base-ui chunk in output — confirms #5
npm run check:chunks                                   # Login 142.9 kB / 160 kB budget
npm run lint                                            # clean
npm run format:check                                    # clean
npm run check:palette                                    # 431 files, zero off-token utilities
sail pest --group=structure                              # 33/33 passed
```

## Open questions

None.
