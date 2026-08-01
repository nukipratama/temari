#!/usr/bin/env bash
set -euo pipefail

# Configure a linked git worktree's .env so its Sail stack can run alongside
# the main checkout's (and other worktrees') without port collisions.
#
#   ./scripts/worktree-setup.sh <slot: 1|2|3>
#
# Run once per worktree, right after `git worktree add` / EnterWorktree. See
# the "temari" skill's "Parallel worktrees" section.

usage() {
  echo "Usage: $0 <slot: 1|2|3>" >&2
  exit 1
}

[ $# -eq 1 ] || usage

case "$1" in
  1) APP_PORT=7011; VITE_PORT=7012 ;;
  2) APP_PORT=7021; VITE_PORT=7022 ;;
  3) APP_PORT=7031; VITE_PORT=7032 ;;
  *) usage ;;
esac

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Linked worktrees resolve --git-common-dir to the main checkout's .git; the
# main checkout resolves it to its own ".git". Refuse to touch main's .env.
if [ "$(git rev-parse --git-common-dir)" = ".git" ]; then
  echo "worktree-setup: this is the main checkout, not a linked worktree — refusing to touch its .env" >&2
  exit 1
fi

[ -f .env ] || cp .env.example .env

set_env_var() {
  if grep -qE "^${1}=" .env; then
    sed -i.bak -E "s|^${1}=.*|${1}=${2}|" .env && rm -f .env.bak
  else
    printf '%s=%s\n' "$1" "$2" >> .env
  fi
}

set_env_var APP_PORT "$APP_PORT"
set_env_var VITE_PORT "$VITE_PORT"
set_env_var APP_URL "http://localhost:${APP_PORT}"
# Host port 0 = "assign any free ephemeral port". DB/Redis are never reached
# from the host here (sail mysql / sail artisan tinker exec into the
# container), so there's nothing to pin these to — just avoid the collision.
set_env_var FORWARD_DB_PORT 0
set_env_var FORWARD_REDIS_PORT 0

cat <<EOF
worktree-setup: slot $1 configured — APP_PORT=$APP_PORT, VITE_PORT=$VITE_PORT.

Next (vendor/ is empty on a fresh worktree, so vendor/bin/sail doesn't exist
yet — bring the stack up and install once with plain docker compose first):
  docker compose up -d
  docker compose exec -T app composer install
  ./vendor/bin/sail npm ci             # sail works from here on

If npm ci fails with EACCES: the node_modules named volume is created
root-owned on first boot. One-time fix:
  docker compose exec -u root app chown -R www-data:www-data node_modules

To actually load pages in a browser (not just run the automated test suites,
which use their own self-initializing mysql_test/redis_test and don't need
this), also run once:
  ./vendor/bin/sail artisan key:generate   # .env.example ships APP_KEY empty
  ./vendor/bin/sail artisan migrate
  ./vendor/bin/sail npm run dev            # or `npm run build` for a one-shot build
EOF
