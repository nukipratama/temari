# Prod mysql image: stock mysql + the idempotent bootstrap baked into initdb, so a
# FRESH volume on ANY machine self-initializes the app's schemas + user + grants.
# Baked (not bind-mounted) because the CI runner is docker-out-of-docker —
# a bind mount of a repo path resolves to an empty dir the host daemon can't see.
# Existing volumes skip initdb (already set up); the script is idempotent regardless.
# Digest-pinned like the app's FrankenPHP/Node bases so a floating-tag repush can't
# swap the stateful DB engine out from under a rebuild without a reviewable diff.
# = mysql:8.4 (8.4.10). Refresh after a bump with:
#   docker buildx imagetools inspect mysql:8.4 --format '{{.Manifest.Digest}}'
FROM mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242
COPY init/01-databases.sh /docker-entrypoint-initdb.d/01-databases.sh
