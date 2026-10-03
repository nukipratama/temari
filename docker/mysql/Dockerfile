# Prod mysql image: stock mysql + the idempotent bootstrap baked into initdb, so a
# FRESH volume on ANY machine self-initializes the app's schemas + user + grants.
# Baked (not bind-mounted) because the CI runner is docker-out-of-docker —
# a bind mount of a repo path resolves to an empty dir the host daemon can't see.
# Existing volumes skip initdb (already set up); the script is idempotent regardless.
# Digest-pinned like the app's FrankenPHP/Node bases so a floating-tag repush can't
# swap the stateful DB engine out from under a rebuild without a reviewable diff.
# = mysql:8.4 (8.4.10). Refresh after a bump with:
#   docker buildx imagetools inspect mysql:8.4 --format '{{.Manifest.Digest}}'
FROM mysql:26.7@sha256:9d48c42f8341068f199116dfccb919b607c99765b5c61e549a548a43033471a4
COPY init/01-databases.sh /docker-entrypoint-initdb.d/01-databases.sh
