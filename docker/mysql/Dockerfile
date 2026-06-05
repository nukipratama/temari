# Prod mysql image: stock mysql + the idempotent bootstrap baked into initdb, so a
# FRESH volume on ANY machine self-initializes the app's schemas + user + grants.
# Baked (not bind-mounted) because the homelab CI runner is docker-out-of-docker —
# a bind mount of a repo path resolves to an empty dir the host daemon can't see.
# Existing volumes skip initdb (already set up); the script is idempotent regardless.
FROM mysql:8.4
COPY init/01-databases.sh /docker-entrypoint-initdb.d/01-databases.sh
