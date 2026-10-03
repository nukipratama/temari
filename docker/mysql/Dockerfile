# Prod mysql image: stock mysql + the idempotent bootstrap baked into initdb, so a
# FRESH volume on ANY machine self-initializes the app's schemas + user + grants.
# Baked (not bind-mounted) because the CI runner is docker-out-of-docker —
# a bind mount of a repo path resolves to an empty dir the host daemon can't see.
# Existing volumes skip initdb (already set up); the script is idempotent regardless.
# Digest-pinned like the app's FrankenPHP/Node bases so a floating-tag repush can't
# swap the stateful DB engine out from under a rebuild without a reviewable diff.
# = mysql:9.7 (9.7.2). Refresh after a bump with:
#   docker buildx imagetools inspect mysql:9.7 --format '{{.Manifest.Digest}}'
FROM mysql:9.7@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497
COPY init/01-databases.sh /docker-entrypoint-initdb.d/01-databases.sh
