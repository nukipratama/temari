# Codemods

Filled in by `F3` (the mechanical sweep). Per risk R2 in [../README.md](../README.md) §7, these
scripts **are** the review artifact for the sweep: each pass is a commit of pure script output, so a
reviewer reads the generator rather than hundreds of files of diff, and the sweep is reproducible by
re-running it.

Expected passes, one script per commit:

1. `01-ground-dependent-utilities.mjs` — the utility class migration table in `F3`'s slice doc.
2. `02-iconify-to-lucide.mjs` — `@iconify/react` → `lucide-react` across ~64 files.
3. `03-primitive-swap.mjs` — the 6-of-23 `components/ui/` → shadcn primitive replacement.
4. A `format` + `eslint --fix` pass (tooling, not a custom script).

Empty until `F3` starts.
