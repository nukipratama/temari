## Stacked PRs

Multi-slice work ships as a GitHub stack (`gh stack`, extension `github/gh-stack`): each **layer**
is a PR based on the one below, so a big change is reviewed as small ones and lands in one merge.

**When.** Stack slices that build on each other; a slice that stands alone is a plain PR off `main`.
Parallel work splits by dependency:
- independent parallel slices: a worktree each off `main`, plain PRs;
- parallel slices sharing a foundation: land the foundation as layer 1, build the rest in worktrees
  branched from it, then chain each onto the stack once its worktree is removed.

**Layers.** One reviewable concern per layer, each with its own issue and `Closes #n`. Order:
foundation first (a new primitive, plus any sign-off gallery), then one surface group per layer,
cleanup and docs last. Each PR body opens with `Part of stack #N: A → B → this`. Build the next
layer without waiting for review, stopping only at gates agreed during planning or at a fork. A fix
found while the stack is open goes to the owner as a choice, next layer or a separate PR off `main`,
recommending the stack for related work and `main` for unrelated bugs.

**Building.** Build sequential layers in the main checkout: `gh stack add <branch>` starts the next
layer. Open each PR with a handwritten title and body (`gh pr create --base <layer below>`), then
`gh stack submit --auto` links it into the stack (`--auto` on its own creates drafts with generated
titles). Every layer's PR runs CI, since `pull_request` fires whatever the base; `deploy` fires only
on `main`. Run the full `./vendor/bin/sail bin pest --parallel` suite on the stack top before the
final push, because the gate only runs changed-file tests.

**Worktrees** (verified 2026-09-24): run every `gh stack` command from the main checkout, because
inside a linked worktree the stack is invisible ("not part of a stack"). `gh stack rebase` stops on
any layer checked out in another worktree; remove that worktree first.

**Merging.** The owner merges. An agent merges only on an explicit "go merge": confirm every layer's
CI is green and each diff matches its scope, then `gh stack merge <stack> --squash --yes`, which is
all-or-nothing (merge up to a single PR only when the owner names it). Landing the whole stack pushes
`main` once, so one CI run and one prod deploy; landing layer by layer deploys once per layer.

**Housekeeping.**
- The kanban automation moves cards on its own: editing a PR body sends its issue back to In
  progress, and a merged stack can leave closed issues In progress. Re-check statuses after either.
- A branch added to the stack by mistake stays in `.git/gh-stack` after `git branch -D`; drop its
  entry from that JSON. Save `gh stack sync` for real syncs, since it rebases and force-pushes every
  layer.
- Squash-merged layers are not ancestors of `main`: confirm each PR merged, then `git branch -D` the
  layer branches.
