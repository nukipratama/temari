# Sourced, not executed. Restores GIT_DIR/GIT_WORK_TREE when git cannot read the
# checkout on its own.
#
# Composer strips GIT_DIR and GIT_WORK_TREE from the environment of every script
# it runs, and a worktree's own .git is a file pointing at a host path outside
# the container's bind mount — so under `composer gate` a worktree has no repo at
# all, for this script and for every tool the gate shells out to. The override
# scripts/worktree-setup.sh writes keeps a copy under a name Composer ignores.
#
# POSIX sh, not bash: the dev image is Alpine and ships no bash.
if [ -n "${TEMARI_GIT_DIR:-}" ] && ! git rev-parse --git-dir >/dev/null 2>&1; then
  GIT_DIR=$TEMARI_GIT_DIR
  GIT_WORK_TREE=$(pwd)
  export GIT_DIR GIT_WORK_TREE
fi
