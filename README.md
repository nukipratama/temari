# Teman Lari

Personal Laravel app, vibe-coded end-to-end. Fully containerized via Laravel Sail — no PHP/Composer/Node on the host.

## Stack

- Laravel 13.5.0 + Blade + Tailwind (via `@tailwindcss/vite`)
- PHP 8.4 (pinned in compose.yaml)
- MySQL 8.4 (dev + isolated test container)
- Redis (dev + isolated test container)
- Mailpit (mail catcher, UI at port 7006)
- Pest 4 + Larastan level 8 + Pint (PSR-12) + Rector + Infection
- Telescope (local debug), Horizon (queue dashboard), Pulse (perf dashboard)

## First-time setup

```bash
# 1. Set Sail file ownership env vars (match your host UID/GID)
echo "WWWUSER=$(id -u)"  >> .env
echo "WWWGROUP=$(id -g)" >> .env

# 2. Bring up the Sail stack (first run pulls images, ~2-5 min)
./vendor/bin/sail up -d

# 3. App setup (composer install auto-wires git hooks via post-install-cmd)
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# 4. (Optional) Install Boost agent guidance (interactive)
./vendor/bin/sail artisan boost:install
```

App is at **http://localhost:7001**.

## Day-to-day

```bash
./vendor/bin/sail composer run dev   # Vite HMR + queue listener + log watcher
./vendor/bin/sail pest               # tests
./vendor/bin/sail pint               # auto-format
./vendor/bin/sail phpstan analyse    # static analysis
./vendor/bin/sail rector --dry-run   # refactor suggestions
```

## Ports (host → container)

| Service        | Host port | Internal |
|---------------:|:---------:|:--------:|
| App (Nginx)    | 7001      | 80       |
| Vite HMR       | 7002      | 5173     |
| MySQL (dev)    | 7003      | 3306     |
| Redis (dev)    | 7004      | 6379     |
| Mailpit SMTP   | 7005      | 1025     |
| Mailpit UI     | 7006      | 8025     |

The test stack (`mysql_test`, `redis_test`) runs without host port forwards — tests reach it over the compose network.

## Test stack

Pest tests run against `mysql_test` (tmpfs-backed, ephemeral) and `redis_test` containers, configured in `phpunit.xml`. Same image versions as prod for parity. Each `sail up` gives a fresh database; `RefreshDatabase` trait handles per-test reset.

CI uses GitHub Actions service containers (mysql:8.4 + redis:alpine) — every workflow run gets a fresh DB.

## Quality gates

| Where        | Runs                                                                  |
|:-------------|:----------------------------------------------------------------------|
| pre-commit   | `pint` (auto-format staged PHP) + `phpstan` (whole `app/`)            |
| commit-msg   | Conventional Commits format check                                     |
| post-commit  | Append entry to `CHANGELOG.md` and amend into HEAD                    |
| pre-push     | Block direct pushes to `main` on `origin` (force or not). Use feature branch + PR + GitHub UI merge |
| CI           | `pint --test`, `phpstan`, `rector --dry-run`, `pest --coverage --min=100`, `pest --mutate` |

100% line coverage gate is enforced in CI (`pest --coverage --min=100`). `TelescopeServiceProvider` and `HorizonServiceProvider` are excluded in `phpunit.xml` — both are framework-wiring with closures that only fire under runtime conditions and aren't meaningfully testable in isolation.

## Branch workflow

Direct pushes to `main` are blocked client-side by `.githooks/pre-push`. To land a change:

```bash
git switch -c feat/your-change
# ... edit + commit ...
git push -u origin feat/your-change
gh pr create --fill           # or open in the GitHub UI
# CI runs; merge once green via the GitHub UI
```

Bypass once with `git push --no-verify` (only for genuine emergencies — there's no server-side enforcement on GitHub Free private repos).

## Deploy

Target: **homelab** (TBD — separate task). Production env overrides are sketched in `.env.example` (commented out): `LOG_CHANNEL=stderr`, `APP_ENV=production`, etc. Plug into whatever runtime the homelab settles on.
