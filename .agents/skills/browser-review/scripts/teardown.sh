#!/bin/sh
# Restore the dev env after a browser-review run. Run inside the Sail `app`
# container:  ./vendor/bin/sail exec app sh .claude/skills/browser-review/scripts/teardown.sh
set -e

# Screenshots are left in place (the latest sweep only — shoot.mjs clears prior
# batches when it next runs). They're gitignored, so they cost nothing to keep
# around for review after teardown; the next sweep replaces them.

# Undo the `--no-save` driver install. It only added playwright + playwright-core
# (not in the lockfile), so deleting those two dirs returns node_modules to its
# lockfile state — no `npm ci` needed (and none of its permission/runtime cost).
echo "→ removing the --no-save playwright driver…"
rm -rf node_modules/playwright node_modules/playwright-core

echo "✓ teardown done (screenshots kept; native chromium stays until the container is recreated)"
