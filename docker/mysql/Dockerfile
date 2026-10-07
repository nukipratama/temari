# Prod mysql image: stock mysql + mysqlbinlog + the idempotent bootstrap baked into initdb, so a
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
# mysqlbinlog ships in the client package, not the minimal server: install the one matching
# this server's exact version so scripts/deploy/replay-binlogs.sh finds it, with no network at recovery time.
RUN version=$(rpm -q --qf '%{VERSION}-%{RELEASE}' mysql-community-server-minimal) \
 && series=$(echo "$version" | cut -d. -f1,2) \
 && el=${version##*.el} \
 && arch=$(uname -m) \
 && package=/tmp/mysql-community-client.rpm \
 && curl -fsSL -o "$package" "https://repo.mysql.com/yum/mysql-$series-community/el/$el/$arch/mysql-community-client-$version.$arch.rpm" \
 && rpm --import /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql \
 && rpm -K "$package" \
 && rpm -i --nodeps --replacefiles "$package" \
 && rm -f "$package"
