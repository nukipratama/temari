#!/bin/sh
set -eu

per_path=false
if [ "${1:-}" = "--per-path" ]; then
  per_path=true
fi

changed="$(cat)"

hits() {
  printf '%s\n' "$changed" | grep -nE "$1" | cut -d: -f1 | tr '\n' ' '
}

selected=

match() {
  eval "set -- \"\$hits_$1\""
  if [ -z "$selected" ]; then
    [ -n "$1" ]
  else
    case " $1" in
      *" $selected "*) return 0 ;;
      *) return 1 ;;
    esac
  fi
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
STRUCTURE='^resources/js/|^(CLAUDE|README)\.md$|^\.dockerignore$|^docs/(design-tokens|architecture/llm-triggers)\.md$|^\.claude/skills/temari/.*\.md$'
IMAGE='^(Dockerfile|\.dockerignore|composer\.(json|lock)|package(-lock)?\.json)$|^docker/|^\.github/workflows/ci\.yml$'

hits_EVERYTHING="$(hits "$EVERYTHING")"
hits_BACKEND="$(hits "$BACKEND")"
hits_MIRRORS="$(hits "$MIRRORS")"
hits_FRONTEND="$(hits "$FRONTEND")"
hits_DOCKER="$(hits "$DOCKER")"
hits_WORKTREE="$(hits "$WORKTREE")"
hits_STRUCTURE="$(hits "$STRUCTURE")"
hits_IMAGE="$(hits "$IMAGE")"

classify() {
  if match EVERYTHING; then
    backend=true
    frontend=true
    docker=true
    worktree=true
  else
    backend=false
    frontend=false
    docker=false
    worktree=false
    if match BACKEND || match MIRRORS; then
      backend=true
    fi
    if match FRONTEND; then
      frontend=true
    fi
    if match DOCKER; then
      docker=true
    fi
    if match WORKTREE; then
      worktree=true
    fi
  fi

  structure=false
  if [ "$backend" = false ] && match STRUCTURE; then
    structure=true
  fi

  image=false
  if match IMAGE; then
    image=true
  fi
}

if [ "$per_path" = true ]; then
  number=0
  printf '%s\n' "$changed" | while IFS= read -r path; do
    number=$((number + 1))
    selected=$number
    classify
    printf '%s\tbackend=%s\tfrontend=%s\tdocker=%s\tworktree=%s\tstructure=%s\timage=%s\n' "$path" "$backend" "$frontend" "$docker" "$worktree" "$structure" "$image"
  done
else
  classify
  printf 'backend=%s\nfrontend=%s\ndocker=%s\nworktree=%s\nstructure=%s\nimage=%s\n' "$backend" "$frontend" "$docker" "$worktree" "$structure" "$image"
fi
