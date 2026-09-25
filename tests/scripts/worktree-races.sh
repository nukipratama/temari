#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
real_perl="$(command -v perl || true)"
real_flock="$(command -v flock || true)"
backend="${1:-perl}"
case "$backend" in
  perl)
    [ -n "$real_perl" ] || {
      echo 'FAIL: Perl backend test needs perl in PATH' >&2
      exit 1
    }
    force_perl_flock=true
    ;;
  flock)
    [ -n "$real_flock" ] || {
      echo 'SKIP: flock backend is not available' >&2
      exit 0
    }
    force_perl_flock=false
    ;;
  *)
    echo "FAIL: unknown lock backend ${backend}" >&2
    exit 1
    ;;
esac
test_root="$(mktemp -d "${TMPDIR:-/tmp}/temari-worktree-races.XXXXXX")"
test_root="$(cd "$test_root" && pwd -P)"
running_pids=(none)
cleanup() {
  [ -z "${FAKE_RELEASE_CLEAN:-}" ] || touch "$FAKE_RELEASE_CLEAN"
  [ -z "${FAKE_RELEASE_REMOVE:-}" ] || touch "$FAKE_RELEASE_REMOVE"
  for pid in "${running_pids[@]}"; do
    [ "$pid" != none ] || continue
    wait "$pid" 2>/dev/null || true
  done
  rm -rf "$test_root"
}
trap cleanup EXIT

wait_for_file() {
  local path="$1"
  local deadline=$((SECONDS + 10))

  while [ ! -e "$path" ]; do
    [ "$SECONDS" -lt "$deadline" ] || {
      echo "timed out waiting for ${path}" >&2
      return 1
    }
    sleep 0.01
  done
}

wait_for_lines() {
  local path="$1"
  local expected="$2"
  local deadline=$((SECONDS + 10))

  while [ "$(wc -l < "$path" 2>/dev/null || echo 0)" -lt "$expected" ]; do
    [ "$SECONDS" -lt "$deadline" ] || {
      echo "timed out waiting for ${expected} lock attempts in ${path}" >&2
      return 1
    }
    sleep 0.01
  done
}

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

assert_eq() {
  local expected="$1"
  local actual="$2"
  local message="$3"

  [ "$expected" = "$actual" ] || fail "$message (expected '$expected', got '$actual')"
}

init_case() {
  local case_dir="$1"
  export FAKE_MAIN="${case_dir}/main"
  export FAKE_COMMON="${FAKE_MAIN}/.git"
  export FAKE_BIN="${case_dir}/bin"
  export FAKE_LOCK_ATTEMPTS="${case_dir}/lock-attempts"
  export FAKE_CLEAN_LOG="${case_dir}/clean-log"
  export FAKE_CLEAN_STARTED="${case_dir}/clean-started"
  export FAKE_RELEASE_CLEAN="${case_dir}/release-clean"
  export FAKE_WORKTREE_ADD_DONE="${case_dir}/worktree-add-done"
  export FAKE_REMOVE_AFTER_REMOVE="${case_dir}/remove-after-git"
  export FAKE_RELEASE_REMOVE="${case_dir}/release-remove"
  export FAKE_REAL_PERL="$real_perl"
  export FAKE_REAL_FLOCK="$real_flock"
  export TEMARI_FORCE_PERL_FLOCK="$force_perl_flock"
  export PATH="${FAKE_BIN}:${PATH}"

  mkdir -p "${FAKE_MAIN}/scripts" "${FAKE_COMMON}/worktrees" "${FAKE_BIN}" \
    "${FAKE_MAIN}/docker/mysql/init" "${FAKE_MAIN}/storage/logs"
  cp "${repo_root}/scripts/worktree" "${FAKE_MAIN}/scripts/worktree"
  chmod +x "${FAKE_MAIN}/scripts/worktree"
  touch "${FAKE_MAIN}/.env.example" "${FAKE_MAIN}/.env.testing.example" \
    "${FAKE_MAIN}/compose.shared-services.yml" \
    "${FAKE_MAIN}/docker/mysql/init/01-databases.sh"
  : > "$FAKE_LOCK_ATTEMPTS"
  : > "$FAKE_CLEAN_LOG"

  cat > "${FAKE_BIN}/git" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

location="$PWD"
if [ "${1:-}" = -C ]; then
  location="$2"
  shift 2
fi

case "${1:-}" in
  rev-parse)
    shift
    case "${1:-}" in
      --path-format=absolute)
        shift
        case "${1:-}" in
          --git-dir)
            if [ "$location" = "$FAKE_MAIN" ]; then
              printf '%s\n' "$FAKE_COMMON"
            else
              printf '%s/worktrees/%s\n' "$FAKE_COMMON" "$(basename "$location")"
            fi
            ;;
          --git-common-dir)
            printf '%s\n' "$FAKE_COMMON"
            ;;
          *) exit 2 ;;
        esac
        ;;
      --git-dir)
        [ -f "${location}/.active" ] || exit 1
        printf '%s/worktrees/%s\n' "$FAKE_COMMON" "$(basename "$location")"
        ;;
      --verify)
        printf '%s\n' fake-commit
        ;;
      *) exit 2 ;;
    esac
    ;;
  worktree)
    case "${2:-}" in
      prune) ;;
      add)
        target="$3"
        branch="$5"
        mkdir -p "${target}/storage/logs" "${target}/docker/mysql/init"
        cp "$FAKE_MAIN/.env.example" "$FAKE_MAIN/.env.testing.example" "$target/"
        cp "$FAKE_MAIN/docker/mysql/init/01-databases.sh" "$target/docker/mysql/init/"
        printf 'gitdir: %s/worktrees/%s\n' "$FAKE_COMMON" "$(basename "$target")" > "${target}/.git"
        touch "${target}/.active"
        printf '%s\n' "$branch" > "${target}/.fake-branch"
        touch "$FAKE_WORKTREE_ADD_DONE"
        ;;
      remove)
        target="$3"
        rm -rf "$target"
        if [ "${FAKE_HOLD_REMOVE_AFTER_REMOVE:-false}" = true ] &&
          [ "$target" = "${FAKE_REMOVE_TARGET:-}" ]; then
          touch "$FAKE_REMOVE_AFTER_REMOVE"
          while [ ! -e "$FAKE_RELEASE_REMOVE" ]; do sleep 0.01; done
        fi
        ;;
      *) exit 2 ;;
    esac
    ;;
  show-ref)
    [ "${FAKE_EXISTING_BRANCH:-}" = "${4#refs/heads/}" ]
    ;;
  branch)
    ;;
  status)
    if [ "$location" = "${FAKE_UNCOMMITTED_PATH:-}" ]; then
      printf ' M tracked-file\n'
    fi
    ;;
  rev-list)
    printf '%s\n' "${FAKE_UNPUSHED_COUNT:-0}"
    ;;
  *) exit 2 ;;
esac
EOF
  chmod +x "${FAKE_BIN}/git"

  cat > "${FAKE_BIN}/perl" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'lock\n' >> "$FAKE_LOCK_ATTEMPTS"
exec "$FAKE_REAL_PERL" "$@"
EOF
  chmod +x "${FAKE_BIN}/perl"

  cat > "${FAKE_BIN}/flock" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'lock\n' >> "$FAKE_LOCK_ATTEMPTS"
exec "$FAKE_REAL_FLOCK" "$@"
EOF
  chmod +x "${FAKE_BIN}/flock"

  cat > "${FAKE_BIN}/jq" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
cat >/dev/null
printf 'temari\n'
EOF
  chmod +x "${FAKE_BIN}/jq"

  cat > "${FAKE_BIN}/mysql" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
cat >/dev/null || true
exit 0
EOF
  chmod +x "${FAKE_BIN}/mysql"

  cat > "${FAKE_BIN}/docker" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

arguments="$*"
if [[ "$arguments" == *"-p temari-slot"*" down "* ]]; then
  printf '%s\n' "$arguments" >> "$FAKE_CLEAN_LOG"
  if [ "${FAKE_HOLD_CLEAN:-false}" = true ]; then
    touch "$FAKE_CLEAN_STARTED"
    while [ ! -e "$FAKE_RELEASE_CLEAN" ]; do sleep 0.01; done
  fi
  [ "${FAKE_FAIL_CLEAN:-false}" = false ] || exit "$FAKE_FAIL_CLEAN"
  printf 'fake compose down output\n'
  exit 0
fi

case "${1:-}" in
  compose)
    if [[ "$arguments" == *" config --format json"* ]]; then
      printf '{"name":"temari"}\n'
    elif [[ "$arguments" == *" ps --status running -q "* ]]; then
      printf 'fake-container\n'
    elif [[ "$arguments" == *" exec "* ]]; then
      if [[ "$arguments" == *" mysql mysql "* || "$arguments" == *" mysql-test mysql "* ]]; then
        printf '%s\n' "$arguments" | "$FAKE_BIN/mysql"
      else
        cat >/dev/null || true
      fi
    fi
    ;;
  volume)
    if [ "${2:-}" = ls ]; then
      :
    fi
    ;;
  *) exit 2 ;;
esac
EOF
  chmod +x "${FAKE_BIN}/docker"
}

make_worktree() {
  local path="$1"
  mkdir -p "${path}/storage/logs" "${path}/docker/mysql/init"
  cp "$FAKE_MAIN/.env.example" "$FAKE_MAIN/.env.testing.example" "$path/"
  cp "$FAKE_MAIN/docker/mysql/init/01-databases.sh" "$path/docker/mysql/init/"
  printf 'gitdir: %s/worktrees/%s\n' "$FAKE_COMMON" "$(basename "$path")" > "${path}/.git"
  touch "${path}/.active"
}

make_stale_slot() {
  local slot="$1"
  local owner="$2"
  mkdir -p "${FAKE_COMMON}/temari-worktree-slots/slot-${slot}"
  printf '%s\n' "$owner" > "${FAKE_COMMON}/temari-worktree-slots/slot-${slot}/path"
  printf 'worktree-old\n' > "${FAKE_COMMON}/temari-worktree-slots/slot-${slot}/branch"
}

assert_no_slot_lock_glob_matches() {
  local path
  for path in "${FAKE_COMMON}/temari-worktree-slots"/slot-*.lock*; do
    [ ! -e "$path" ] || fail "persistent lock file matched prune's slot glob: ${path}"
  done
}

case_dir="${test_root}/incomplete-reservations"
init_case "$case_dir"
mkdir -p "${FAKE_COMMON}/temari-worktree-slots/slot-1" \
  "${FAKE_COMMON}/temari-worktree-slots/slot-2"
touch -t 200001010000 "${FAKE_COMMON}/temari-worktree-slots/slot-1"
"${FAKE_MAIN}/scripts/worktree" prune > "${case_dir}/prune.out" 2>&1 || {
  cat "${case_dir}/prune.out" >&2
  fail 'prune failed while reclaiming an incomplete slot'
}
[ ! -e "${FAKE_COMMON}/temari-worktree-slots/slot-1" ] || fail 'old incomplete reservation was not reclaimed'
[ -d "${FAKE_COMMON}/temari-worktree-slots/slot-2" ] || fail 'recent incomplete reservation was reclaimed'
grep -q 'reclaiming incomplete slot 1' "${case_dir}/prune.out" || fail 'prune did not identify the incomplete reservation'
assert_eq 1 "$(wc -l < "$FAKE_CLEAN_LOG" | tr -d ' ')" 'prune did not clean exactly the old incomplete reservation'
echo 'PASS: prune reclaims old incomplete reservations and leaves recent ones alone'

case_dir="${test_root}/two-adopters"
init_case "$case_dir"
make_stale_slot 1 "${case_dir}/removed-worktree"
make_worktree "${case_dir}/first"
make_worktree "${case_dir}/second"
export FAKE_HOLD_CLEAN=true

"${FAKE_MAIN}/scripts/worktree" adopt "${case_dir}/first" > "${case_dir}/first.out" 2>&1 &
first_pid=$!
running_pids=("$first_pid")
wait_for_file "$FAKE_CLEAN_STARTED"
"${FAKE_MAIN}/scripts/worktree" adopt "${case_dir}/second" > "${case_dir}/second.out" 2>&1 &
second_pid=$!
running_pids+=("$second_pid")
wait_for_lines "$FAKE_LOCK_ATTEMPTS" 2
touch "$FAKE_RELEASE_CLEAN"
wait "$first_pid" || { cat "${case_dir}/first.out" >&2; fail 'first adopter failed'; }
running_pids=("$second_pid")
wait "$second_pid" || { cat "${case_dir}/second.out" >&2; fail 'second adopter failed'; }
running_pids=(none)

assert_eq 1 "$(wc -l < "$FAKE_CLEAN_LOG" | tr -d ' ')" 'two adopters cleaned one stale slot more than once'
assert_eq "${case_dir}/first" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'first adopter did not retain reclaimed slot 1'
assert_eq "${case_dir}/second" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-2/path")" 'second adopter did not move to slot 2'
unset FAKE_HOLD_CLEAN
echo 'PASS: two stale adopters serialize cleanup and claim separate slots'

case_dir="${test_root}/prune-create"
init_case "$case_dir"
make_stale_slot 1 "${case_dir}/removed-worktree"
export FAKE_HOLD_CLEAN=true

"${FAKE_MAIN}/scripts/worktree" prune > "${case_dir}/prune.out" 2>&1 &
prune_pid=$!
running_pids=("$prune_pid")
wait_for_file "$FAKE_CLEAN_STARTED"
"${FAKE_MAIN}/scripts/worktree" create creator > "${case_dir}/create.out" 2>&1 &
create_pid=$!
running_pids+=("$create_pid")
wait_for_file "$FAKE_WORKTREE_ADD_DONE"
wait_for_lines "$FAKE_LOCK_ATTEMPTS" 2
touch "$FAKE_RELEASE_CLEAN"
wait "$prune_pid" || { cat "${case_dir}/prune.out" >&2; fail 'prune failed'; }
running_pids=("$create_pid")
wait "$create_pid" || { cat "${case_dir}/create.out" >&2; fail 'create failed'; }
running_pids=(none)
assert_no_slot_lock_glob_matches

assert_eq 1 "$(wc -l < "$FAKE_CLEAN_LOG" | tr -d ' ')" 'prune and create both cleaned the same stale slot'
assert_eq "${FAKE_MAIN}/.claude/worktrees/creator" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'create did not claim the slot after prune released it'
[ -f "${FAKE_COMMON}/temari-worktree-slots/lock-slot-1.lock" ] || fail 'persistent slot 1 mutex was not retained'
unset FAKE_HOLD_CLEAN
echo 'PASS: prune and create serialize stale cleanup and ownership transfer'

case_dir="${test_root}/interrupted-cleanup"
init_case "$case_dir"
make_stale_slot 1 "${case_dir}/removed-worktree"
make_worktree "${case_dir}/adopter"
export FAKE_FAIL_CLEAN=130

if "${FAKE_MAIN}/scripts/worktree" adopt "${case_dir}/adopter" > "${case_dir}/adopt.out" 2>&1; then
  cat "${case_dir}/adopt.out" >&2
  fail 'adopt succeeded despite stale-slot cleanup failure'
fi
assert_eq "${case_dir}/removed-worktree" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'failed cleanup changed the stale reservation owner'
[ ! -d "${FAKE_COMMON}/temari-worktree-slots/slot-2" ] || fail 'adopt claimed another slot after cleanup failed'

unset FAKE_FAIL_CLEAN
if ! "${FAKE_MAIN}/scripts/worktree" prune > "${case_dir}/retry.out" 2>&1; then
  cat "${case_dir}/retry.out" >&2
  fail 'prune could not retry an interrupted stale-slot cleanup'
fi
assert_no_slot_lock_glob_matches
[ ! -d "${FAKE_COMMON}/temari-worktree-slots/slot-1" ] || fail 'successful retry did not release the stale reservation'

make_worktree "${case_dir}/live-owner"
make_worktree "${case_dir}/next-adopter"
mkdir -p "${FAKE_COMMON}/temari-worktree-slots/slot-1"
printf '%s\n' "${case_dir}/live-owner" > "${FAKE_COMMON}/temari-worktree-slots/slot-1/path"
printf 'worktree-live\n' > "${FAKE_COMMON}/temari-worktree-slots/slot-1/branch"
"${FAKE_MAIN}/scripts/worktree" adopt "${case_dir}/next-adopter" > "${case_dir}/next-adopter.out" 2>&1 || {
  cat "${case_dir}/next-adopter.out" >&2
  fail 'adopter failed while a live owner held slot 1'
}
assert_eq "${case_dir}/live-owner" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'retry stole a live slot owner'
assert_eq "${case_dir}/next-adopter" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-2/path")" 'adopter did not skip the live owner'
assert_eq 2 "$(wc -l < "$FAKE_CLEAN_LOG" | tr -d ' ')" 'live owner caused a new cleanup after retry'
echo 'PASS: interrupted reclaim retries and leaves live owners untouched'

case_dir="${test_root}/slot-capacity"
init_case "$case_dir"
for slot in $(seq 1 84); do
  owner="${case_dir}/owner-${slot}"
  make_worktree "$owner"
  mkdir -p "${FAKE_COMMON}/temari-worktree-slots/slot-${slot}"
  printf '%s\n' "$owner" > "${FAKE_COMMON}/temari-worktree-slots/slot-${slot}/path"
  printf 'worktree-owner-%s\n' "$slot" > "${FAKE_COMMON}/temari-worktree-slots/slot-${slot}/branch"
done

if "${FAKE_MAIN}/scripts/worktree" create creator > "${case_dir}/create.out" 2>&1; then
  cat "${case_dir}/create.out" >&2
  fail 'create succeeded after all documented worktree slots were occupied'
fi
assert_eq 84 "$(wc -l < "$FAKE_LOCK_ATTEMPTS" | tr -d ' ')" 'allocator attempted slots beyond the documented limit'
if ! grep -q 'no free worktree slots; the maximum is 84' "${case_dir}/create.out"; then
  cat "${case_dir}/create.out" >&2
  fail 'allocator did not report that all documented worktree slots were occupied'
fi
echo 'PASS: allocation stops at the documented 84-slot limit'

case_dir="${test_root}/remove-prune-create"
init_case "$case_dir"
remover="${FAKE_MAIN}/.claude/worktrees/remover"
make_worktree "$remover"
printf '1\n' > "${remover}/.claude-worktree-slot"
mkdir -p "${FAKE_COMMON}/temari-worktree-slots/slot-1"
printf '%s\n' "$remover" > "${FAKE_COMMON}/temari-worktree-slots/slot-1/path"
printf 'worktree-remover\n' > "${FAKE_COMMON}/temari-worktree-slots/slot-1/branch"
export FAKE_EXISTING_BRANCH=worktree-remover
export FAKE_UNCOMMITTED_PATH="$remover"

if "${FAKE_MAIN}/scripts/worktree" remove "$remover" > "${case_dir}/uncommitted.out" 2>&1; then
  fail 'remove accepted uncommitted work'
fi
assert_eq "$remover" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'uncommitted refusal changed the slot owner'
[ -d "$remover" ] || fail 'uncommitted refusal removed the worktree'

unset FAKE_UNCOMMITTED_PATH
export FAKE_UNPUSHED_COUNT=1
if "${FAKE_MAIN}/scripts/worktree" remove "$remover" > "${case_dir}/unpushed.out" 2>&1; then
  fail 'remove accepted unpushed commits'
fi
assert_eq "$remover" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'unpushed refusal changed the slot owner'
[ -d "$remover" ] || fail 'unpushed refusal removed the worktree'

export FAKE_UNPUSHED_COUNT=0
export FAKE_HOLD_REMOVE_AFTER_REMOVE=true
export FAKE_REMOVE_TARGET="$remover"
"${FAKE_MAIN}/scripts/worktree" remove "$remover" > "${case_dir}/remove.out" 2>&1 &
remove_pid=$!
running_pids=("$remove_pid")
wait_for_file "$FAKE_REMOVE_AFTER_REMOVE"
"${FAKE_MAIN}/scripts/worktree" create creator > "${case_dir}/create.out" 2>&1 &
create_pid=$!
running_pids+=("$create_pid")
wait_for_file "$FAKE_WORKTREE_ADD_DONE"
wait_for_lines "$FAKE_LOCK_ATTEMPTS" 2
touch "$FAKE_RELEASE_REMOVE"
wait "$remove_pid" || { cat "${case_dir}/remove.out" >&2; fail 'remove failed'; }
running_pids=("$create_pid")
wait "$create_pid" || { cat "${case_dir}/create.out" >&2; fail 'create failed after remove'; }
running_pids=(none)

[ ! -e "$remover" ] || fail 'remove left the old worktree on disk'
assert_eq "${FAKE_MAIN}/.claude/worktrees/creator" "$(<"${FAKE_COMMON}/temari-worktree-slots/slot-1/path")" 'remove erased the new owner reservation'
assert_eq 0 "$(wc -l < "$FAKE_CLEAN_LOG" | tr -d ' ')" 'create redundantly reclaimed the slot while remove held it'
echo 'PASS: remove holds the slot through Git removal and preserves safety refusals'
echo "PASS: lock backend ${backend}"
