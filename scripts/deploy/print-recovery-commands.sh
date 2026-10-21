#!/bin/sh
set -eu

# Prints the manual recovery for a deploy that failed after its migrations ran,
# as Markdown for the job log and summary. Reads BACKUP_DIR, BACKUP_SUFFIX,
# PREVIOUS_SHA, DEPLOY_ENABLED_MAINTENANCE and GITHUB_REPOSITORY; all but the
# last may be unset when the deploy failed early.

compose="sudo docker compose -f compose.prod.yaml"
app_dump="${BACKUP_DIR:-}/pre-deploy-${BACKUP_SUFFIX:-}.sql.gz"
analytics_dump="${BACKUP_DIR:-}/analytics-pre-deploy-${BACKUP_SUFFIX:-}.sql.gz"

echo "### Recover the failed deploy"
echo
echo "The deploy left maintenance as it was. Run these in order: the \`gh\` command from your machine, the rest on the homelab host from the repo root. Skip step 2 when no migration in this deploy changed or dropped data."
echo
echo '```bash'
echo "# 1. Stop the writers."
echo "$compose stop scheduler horizon"
echo "# 2. Restore the dumps taken just before this deploy's migrations."
if [ -e "$app_dump" ]; then
  echo "sudo ./scripts/restore-db.sh $app_dump"
else
  echo "# This run wrote no pre-deploy dump; restore the newest one listed by: sudo ./scripts/restore-db.sh"
fi
if [ -e "$analytics_dump" ]; then
  echo "sudo ./scripts/restore-db.sh $analytics_dump"
fi
echo "# 3. Roll the code back to the image that was live before this deploy."
if [ -n "${PREVIOUS_SHA:-}" ]; then
  echo "gh workflow run release-rollback.yml --repo $GITHUB_REPOSITORY -f sha=$PREVIOUS_SHA"
else
  echo "gh workflow run release-rollback.yml --repo $GITHUB_REPOSITORY"
fi
if [ "${DEPLOY_ENABLED_MAINTENANCE:-false}" = "true" ]; then
  echo "# 4. Once the rollback run is green, lift the maintenance this deploy turned on."
  echo "$compose exec -T app php artisan up"
fi
echo '```'
