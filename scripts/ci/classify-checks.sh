#!/bin/sh
set -eu

changed="$(cat)"

match() {
  printf '%s\n' "$changed" | grep -qE "$1"
}

# Anything infrastructural runs the lot (deploy stays main-push-only).
ARCH='^(Dockerfile|compose[^/]*\.ya?ml|\.env\.example)$|^(docker|\.github)/|^public/(frankenphp-worker\.php|\.htaccess)$'

# Backend also owns the token-mirror structure tests, which read files that
# look like frontend or documentation.
MIRRORS='^resources/(css|views|brand)/|^resources/js/lib/(shareCard|runcard|chartTokens)\.ts$|^resources/js/lib/card/palette\.ts$'
DOCS_READ_BY_TESTS='^(CLAUDE|README)\.md$|^docs/design-tokens\.md$|^\.claude/skills/temari/SKILL\.md$'
BACKEND='^(app|bootstrap|config|database|routes|tests)/|^public/.*\.php$|^scripts/(worktree|tl)$|^scripts/.*\.(php|sh)$|^composer\.(json|lock)$|^(phpunit\.xml|artisan|rector\.php|pint\.json)$|^phpstan.*\.neon$'
FRONTEND='^resources/(js|css|views)/|^public/(sw\.js|offline\.html|manifest\.webmanifest|robots\.txt)$|^scripts/.*\.mjs$|^(package\.json|package-lock\.json|vite\.config\.ts|vitest\.config\.ts|prettier\.config\.js|eslint\.config\.js)$|^tsconfig.*\.json$|^\.prettierrc'

if match "$ARCH"; then
  backend=true
  frontend=true
  docker=true
else
  backend=false
  frontend=false
  docker=false
  if match "$BACKEND" || match "$MIRRORS" || match "$DOCS_READ_BY_TESTS"; then
    backend=true
  fi
  if match "$FRONTEND"; then
    frontend=true
  fi
fi

printf 'backend=%s\nfrontend=%s\ndocker=%s\n' "$backend" "$frontend" "$docker"
