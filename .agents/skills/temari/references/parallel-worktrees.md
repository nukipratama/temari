## Parallel worktrees

Running several implementation agents concurrently, each in its own `git worktree`, is safe — setup
pins each worktree's Compose project to `temari-slot<N>`, so every worktree gets its own isolated
`app` container, network and volumes, and none of them can resolve to the main checkout's `temari`
project (`create` also refuses a name that would). `mysql`/`redis`/`mysql_test`/`redis_test` are never
published to the host at all (only reached via `sail mysql`/`sail artisan tinker`/`docker exec`),
so the only real collision on the main checkout is **fixed host ports**
(`.env.example`'s `APP_PORT`/`VITE_PORT`), which two worktrees would both try to bind off an
unmodified `.env`. No changes needed to `.githooks/pre-commit` — its `docker compose ps` check
already resolves per-cwd correctly.

Worktrees don't each get their own MySQL/Redis, though — see "Shared services" below.

Use the worktree creation and lifecycle guidance in [AGENTS.md](../../../../AGENTS.md). This section
records the environment invariants that every worktree setup must preserve.

Slot numbering is a formula (`scripts/worktree`), not a fixed table, and `create` picks the slot —
never hand-pick one: `APP_PORT = 7000 + slot*10 + 1`, `VITE_PORT = +2` (main stays 7001/7002, slot 1
is 7011/7012, slot 2 is 7021/7022, and so on), `COMPOSE_PROJECT_NAME = temari-slot<N>`. Every
host-forwarded port stays in the 7000 range; a new forwarded service continues the sequence. The real
ceiling is the shared Redis `--databases 256`: dev takes indices `slot*3..+2`, so slot 84 is the last
one that fits. Setup writes an untracked `compose.override.yaml` mounting the shared git dir so the
gate's changed-file steps work, and joining the shared-services network, brings the shared stack and this worktree's `app` up,
then bootstraps the app: `composer install`, `key:generate`, **both** migration sets, `npm ci` and
`npm run build`. Every step is guarded or idempotent, so re-running `scripts/worktree create
<name>` after a failure reuses the existing worktree and resumes setup. `vendor/` is empty when it
starts, so setup uses plain `docker compose exec` for all of it; `./vendor/bin/sail` works for
everything afterwards.

Slot ownership is serialized by a persistent per-slot lock file: `create`, `adopt`, and `prune` hold
it from the stale-owner check through cleanup and ownership transfer; `remove` holds it through
cleanup, Git worktree removal, and reservation release after the existing safety checks. Failed-create
rollback releases a reservation under the same lock after confirming that it still owns the slot.
`scripts/worktree` uses `flock(1)` when available and Perl `Fcntl::flock` otherwise, and reports a clear
error if neither is installed. `tests/scripts/worktree-races.sh` exercises both lock backends when
`flock` is available, using an isolated fake checkout.

### Shared services

Each worktree's own MySQL+Redis was cheap at 2-3 worktrees but doesn't scale: `docker stats` during
a real `pest --parallel` run showed `mysql_test` peaking at 168% CPU / 641MB — a real cost per idle
worktree, not just `app`'s own (dominant, ~300% CPU) load. So `compose.shared-services.yml` (a
separate, pinned-name Compose project: `temari-shared-services`) runs exactly one `mysql`, `redis`,
`mysql-test` and `redis-test`, and every worktree's `app` talks to those instead of its own —
`compose.yaml`'s `mysql`/`redis`/`mysql_test`/`redis_test` are gated behind a `local-db` Compose
profile that only the main checkout enables by default (`.env.example`'s `COMPOSE_PROFILES=local-db`);
`scripts/worktree` setup clears it for a worktree, so `docker compose up` there never starts them.

Isolation moves from *separate containers* to *separate schema* (MySQL: `temari_slot{N}` /
`temari_slot{N}_analytics`) and *separate logical Redis DB index* (`3*N` for dev's
default/cache/pulse). `migrate:fresh` only ever touches the schema its own connection is bound to,
so one worktree's `migrate:fresh`/paratest run still can't wipe or lock another's — the safety
property worktrees used to get from separate containers now comes from separate schemas instead.
Schema provisioning reuses `docker/mysql/init/01-databases.sh` directly against the shared instance
(same script dev's own `mysql` already runs on a fresh volume).

Tests get the same treatment via **`.env.testing`** (gitignored, bootstrapped from
`.env.testing.example` — same pattern as `.env`/`.env.example`): `phpunit.xml` no longer hardcodes
`DB_HOST`/`DB_DATABASE`/`DB_ANALYTICS_DATABASE`/`REDIS_HOST`, since Laravel swaps in `.env.testing`
whenever `APP_ENV=testing` (which `phpunit.xml` still sets). Each worktree's `.env.testing` points
at the shared test services with `DB_DATABASE=temari_testing_slot{N}`; Laravel's `ParallelTesting`
still appends its own per-worker suffix on top (`_test_{token}`), so the final name
(`temari_testing_slot{N}_test_{token}`) is unique per worktree *and* per paratest worker with no
custom resolver needed. `docker/mysql-test-init.sh`'s grant is already a `` `temari_testing%` ``
wildcard, so paratest self-creates each per-worker schema exactly like before — the one thing that
wildcard doesn't cover is the slot's own *unsuffixed* base schema (previously auto-created by
`mysql_test`'s `MYSQL_DATABASE` env var at container boot, which the shared instance has no
per-slot equivalent of), so `scripts/worktree` setup creates that one explicitly.

`vendor/`/`node_modules` stay per-worktree, unchanged — see below, this was deliberately not
folded into the consolidation.

**The Compose gotcha that bit this once, so it doesn't again.** `compose.shared-services.yml` has a
pinned `name:`, but Compose *also* tracks the invoking directory as part of a project's identity
(its `working_dir` label). A worktree calling `docker compose up` from its own directory looks like
a config change to Compose even though the file content is identical — Compose then **recreates**
the containers to match, and since `mysql-test`/`redis-test` are tmpfs, that recreate silently
wipes every other worktree's test schema. Measured directly: benchmarking 3 concurrent worktrees
lost 2 of 3 test schemas mid-run this way. Every call against `compose.shared-services.yml` in
`scripts/worktree` setup goes through its `shared()` helper, which pins `--project-directory` to the
main checkout (stable across every worktree) specifically to prevent this — **any new call against
that file must go through `shared()` too**, never a bare `docker compose -f compose.shared-services.yml`.

**Both** migration sets matters. `analytics` is a second connection with its own migration path, so
a plain `artisan migrate` does not touch it — the script also runs
`migrate --database=analytics --path=database/migrations/analytics`. Without it `strava_sync_logs`
and `ai_token_usages` are missing and `/pulse` + `/devtools/narration` 500. This lived only in the script's
printed next-steps until #614, which is exactly why every worktree skipped it.

Composer's and npm's **download caches** are shared across worktrees via fixed-name volumes
(`temari_composer_cache`/`temari_npm_cache` in `compose.yaml`) — only `vendor/`/`node_modules`
themselves stay per-worktree (each must reflect that branch's own lockfile), so the second+
worktree's install just replays from cache instead of re-downloading over the network.
`scripts/worktree` setup chowns all three cache-type volumes (`node_modules` included) to `www-data`
right after bringing the stack up, since they're created root-owned on first boot and the container
always runs as `www-data` — no manual fix needed.

**Throughput, rule of thumb.** `docker stats` during a real `pest --parallel` run showed `app`
dominates resource use regardless of the shared-services consolidation (peak ~300% CPU; `mysql_test`
was a distant second at 168%, everything else negligible) — so the ceiling is CPU, not RAM. That is
why full-suite runs are CI's: the gate's `pest changed` step runs a few paired test files, so any
number of worktrees can gate at once, and a full local `pest --parallel` (including `check:full` —
rector, coverage and the Vite build) is a rare opt-in, **one** worktree at a time. Sharing
MySQL/Redis mainly buys back memory/container overhead for idle worktrees, not CPU headroom during
genuinely concurrent heavy test runs.

**Git hooks are shared, not per-worktree.** `core.hooksPath` lives in the common `.git/config` that
linked worktrees inherit, so every worktree runs the *main checkout's* `.githooks/` at whatever
version that checkout has on disk. A hook edited on a worktree branch is not exercised by that
worktree's commits — invoke the script directly to test it. (If the stored value is absolute, it
pins every worktree to the main checkout; `composer install` re-sets it relative.)

**`commit-msg` has no merge-commit exemption, on purpose**; see the merge-message rule in
[AGENTS.md](../../../../AGENTS.md).

**One fresh-worktree gotcha**, not concurrency-specific: if several worktrees cold-install at the
same moment, one can occasionally fail mid-extraction on a transient bind-mount visibility race —
just re-run `scripts/worktree create <name>`, which resumes rather than redoing. (The old
`MissingAppKeyException` gotcha is gone: the script generates the key itself, and only when `APP_KEY`
is unset, so a re-run never rotates it out from under a live session.)

The Docker image (`temari/dev`) and its build cache are shared across worktrees on purpose (plain
local tag, not project-scoped) — only pass `--build` again if a worktree's slice actually touches
`Dockerfile`/PHP extensions, so two worktrees don't race an in-flight rebuild.

**Slices that build on each other** ship as a stack; see [stacked-prs.md](stacked-prs.md) for how worktrees
and stacks combine.
