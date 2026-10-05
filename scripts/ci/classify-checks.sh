#!/bin/sh
set -eu

changed="$(cat)"

match() {
  printf '%s\n' "$changed" | grep -qE "$1"
}

# The workflow that defines every CI job runs the lot.
EVERYTHING='^\.github/workflows/ci\.yml$'

# Backend also owns the structure tests that read infrastructure, workflow and
# token-mirror files.
MIRRORS='^resources/(css|views|brand)/|^resources/js/lib/(shareCard|runcard|chartTokens)\.ts$|^resources/js/lib/card/palette\.ts$'
BACKEND='^resources/js/types/generated\.ts$|^(app|bootstrap|config|database|deploy|docker|public|routes|tests)/|^\.github/(workflows|actions)/|^scripts/(worktree|tl)$|^scripts/.*\.(php|sh)$|^composer\.(json|lock)$|^compose[^/]*\.ya?ml$|^(Dockerfile|phpunit\.xml|artisan|rector\.php|pint\.json|\.env\.example|\.env\.testing\.example|\.nvmrc|\.gitignore|\.gitattributes)$|^phpstan.*\.neon$'
FRONTEND='^resources/(js|css|views|brand)/|^tests/fixtures/|^\.github/actions/|^\.github/workflows/frontend-ci\.yml$|^public/(sw\.js|offline\.html|manifest\.webmanifest|robots\.txt)$|^scripts/.*\.mjs$|^(package\.json|package-lock\.json|vite\.config\.ts|vitest\.config\.ts|prettier\.config\.js|eslint\.config\.js|\.nvmrc|\.npmrc|\.prettierignore|\.editorconfig|\.gitignore|\.gitattributes)$|^tsconfig.*\.json$|^\.prettierrc'
DOCKER='^(Dockerfile|\.dockerignore)$|^docker/|^public/\.htaccess$'
WORKTREE='^scripts/worktree|^tests/scripts/'
STRUCTURE='^resources/js/|^(CLAUDE|README)\.md$|^\.dockerignore$|^docs/(design-tokens|architecture/llm-triggers)\.md$|^\.agents/skills/temari/.*\.md$'
IMAGE='^(Dockerfile|\.dockerignore|composer\.(json|lock)|package(-lock)?\.json)$|^docker/|^\.github/workflows/ci\.yml$'

if match "$EVERYTHING"; then
  backend=true
  frontend=true
  docker=true
  worktree=true
else
  backend=false
  frontend=false
  docker=false
  worktree=false
  if match "$BACKEND" || match "$MIRRORS"; then
    backend=true
  fi
  if match "$FRONTEND"; then
    frontend=true
  fi
  if match "$DOCKER"; then
    docker=true
  fi
  if match "$WORKTREE"; then
    worktree=true
  fi
fi

structure=false
if [ "$backend" = false ] && match "$STRUCTURE"; then
  structure=true
fi

image=false
if match "$IMAGE"; then
  image=true
fi

printf 'backend=%s\nfrontend=%s\ndocker=%s\nworktree=%s\nstructure=%s\nimage=%s\n' "$backend" "$frontend" "$docker" "$worktree" "$structure" "$image"
