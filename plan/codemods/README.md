# Codemods

Filled in by `F3` (the mechanical sweep). Per risk R2 in [../README.md](../README.md) §7, these
scripts **are** the review artifact for the sweep: each pass is a commit of pure script output, so a
reviewer reads the generator rather than hundreds of files of diff, and the sweep is reproducible by
re-running it (`node plan/codemods/<script>.mjs [--dry-run]`).

Each script's own file header explains what it does and why; see `F3`'s slice doc
([../slices/04-F3-mechanical-sweep.md](../slices/04-F3-mechanical-sweep.md)) for what actually
landed and where it deviated from the plan below.

1. `01-migrate-ground-utilities.mjs` — the ground-dependent utility class migration
   (`bg-surface-*`→`bg-{card,popover,muted,accent,background}`, `text-ink*`→`text-{foreground,text-2,text-3}`,
   `border-line*`→`border-border*`, `font-display`→`font-serif`) across `resources/js`, plus the
   matching rewrite of `resources/brand/grounds.json`'s paper classification and panel registry.
2. `02-swap-icon-runtime.mjs` — repoints every `import { Icon } from '@iconify/react'` at the new
   `resources/js/components/ui/Icon.tsx` wrapper (lucide-react underneath; `@iconify/react` and
   `lib/iconBundle.ts` are gone).
3. `03-rename-legacy-primitives.mjs` — renames `Card.tsx`→`LegacyCard.tsx` and
   `Toggle.tsx`→`Switch.tsx` (TypeScript, and any case-insensitive filesystem, won't allow them to
   coexist with the new lowercase `card.tsx`/`toggle.tsx` shadcn primitives) and repoints every
   consumer's import path.

The 6-of-23 primitive swap itself (`card`/`badge`/`button`/`toggle`/`toggle-group` vendored from
the frozen prototype, `collapsible` not yet used) and the ~37 call sites migrated onto them are
**hand-edited, not scripted** — deciding which of Card's 34 call sites, Chip's 9, and PillButton's
23 have a faithful equivalent in the new vocabulary (vs. a tone/element-type shadcn has no match
for) is real per-call-site judgment, not a mechanical rename. See the slice doc for the full
triage and the two pairings (`Toggle`↔`toggle`, `SectionTabs`↔`toggle-group`) that don't hold up at
all. `format` + `eslint --fix` ran as part of each hand-authored/scripted commit rather than as a
separate pass.
