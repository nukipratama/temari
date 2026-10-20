#!/usr/bin/env bash
# Measures which intervals.icu create call deduplicates a planned workout.
# Each candidate posts the same workout twice on its own far-future date, counts
# that date's events, then deletes every event it created.
# Run against a throwaway account:
#   read -rs ICU_KEY && export ICU_KEY ICU_ATHLETE=i123456 && scripts/measure/intervals-icu-upsert.sh
set -euo pipefail

: "${ICU_ATHLETE:?set ICU_ATHLETE}"
: "${ICU_KEY:?set ICU_KEY}"
command -v jq >/dev/null || { echo "jq is required" >&2; exit 1; }

base="https://intervals.icu/api/v1/athlete/${ICU_ATHLETE}"
created=()

api() {
  local method=$1 path=$2 body=${3:-}
  local args=(-sS -X "$method" -H 'Accept: application/json' -w '\n%{http_code}')
  [ -n "$body" ] && args+=(-H 'Content-Type: application/json' --data "$body")
  curl "${args[@]}" -K <(printf 'user = "API_KEY:%s"\n' "$ICU_KEY") "${base}${path}"
}

call() {
  local out status
  out=$(api "$@")
  status=${out##*$'\n'}
  out=${out%$'\n'*}
  if [ "${status:0:1}" != 2 ]; then
    echo "  $1 $2 -> HTTP $status: $out" >&2
    return 1
  fi
  printf '%s' "$out"
}

cleanup() {
  for id in ${created[@]+"${created[@]}"}; do
    api DELETE "/events/${id}" >/dev/null || echo "  could not delete event $id" >&2
  done
}
trap cleanup EXIT

workout() {
  jq -nc --arg date "$1" --arg uid "${2:-}" --arg ext "${3:-}" '
    {category: "WORKOUT", type: "Run", start_date_local: ($date + "T00:00:00"),
     name: "Upsert probe", description: "- 5km Z2 Pace"}
    + (if $uid != "" then {uid: $uid} else {} end)
    + (if $ext != "" then {external_id: $ext} else {} end)'
}

remember() {
  local ids
  ids=$(jq -r 'if type == "array" then .[].id else .id end' <<<"$1")
  for id in $ids; do
    [[ " ${created[*]-} " == *" $id "* ]] || created+=("$id")
  done
}

event_of() { jq -c 'if type == "array" then .[0] else . end' <<<"$1"; }

report() {
  local name=$1 date=$2 first second listed count
  first=$(event_of "$3")
  second=$(event_of "$4")
  listed=$(call GET "/events?oldest=${date}&newest=${date}")
  count=$(jq 'length' <<<"$listed")
  echo "## $name ($date)"
  echo "  first : $(jq -c '{id, uid, external_id, oauth_client_id}' <<<"$first")"
  echo "  second: $(jq -c '{id, uid, external_id, oauth_client_id}' <<<"$second")"
  echo "  same id: $([ "$(jq -r .id <<<"$first")" = "$(jq -r .id <<<"$second")" ] && echo yes || echo no)"
  echo "  events on date: $count  ids: $(jq -c '[.[].id]' <<<"$listed")"
}

probe() {
  local name=$1 date=$2 path=$3 body=$4 before first second
  before=$(call GET "/events?oldest=${date}&newest=${date}" | jq 'length')
  if [ "$before" != 0 ]; then
    echo "## $name ($date): skipped, the date already has $before events"
    return
  fi
  first=$(call POST "$path" "$body") || { echo "## $name: first create failed"; return; }
  remember "$first"
  second=$(call POST "$path" "$body") || { echo "## $name: second create failed"; return; }
  remember "$second"
  report "$name" "$date" "$first" "$second"
}

d1=2099-03-02 d2=2099-03-03 d3=2099-03-04 d4=2099-03-05 d5=2099-03-06

probe "A plain create, no uid" "$d1" "/events" "$(workout $d1)"

probe "B create, client uid, upsertOnUid=true" "$d2" "/events?upsertOnUid=true" "$(workout $d2 "temari-probe-$d2")"

first=$(call POST "/events" "$(workout $d3)") && remember "$first" && {
  server_uid=$(jq -r .uid <<<"$first")
  second=$(call POST "/events?upsertOnUid=true" "$(workout $d3 "$server_uid")") && remember "$second" &&
    report "C create, then upsertOnUid=true with the server's uid" "$d3" "$first" "$second"
}

probe "D bulk, external_id, upsert=true" "$d4" "/events/bulk?upsert=true" "[$(workout $d4 '' "temari-probe-$d4")]"

probe "E bulk, client uid, upsertOnUid=true" "$d5" "/events/bulk?upsertOnUid=true" "[$(workout $d5 "temari-probe-$d5")]"

cleanup
created=()
echo "## after cleanup"
for d in $d1 $d2 $d3 $d4 $d5; do
  echo "  $d: $(call GET "/events?oldest=${d}&newest=${d}" | jq 'length') events"
done
