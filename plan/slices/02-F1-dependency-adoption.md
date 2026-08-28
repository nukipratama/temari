# F1 — Dependency adoption

**Wave** 1 · **Slot** main checkout · **Blockers** `P0`, `L0` · **Status** todo

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

_To be filled when wave 1 starts._

## Coverage delta

_To be filled when wave 1 starts._

## Verification notes

_To be filled when wave 1 starts._

## Open questions

_To be filled when wave 1 starts._
