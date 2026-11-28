# Prod mysql image: stock mysql + mysqlbinlog + the idempotent bootstrap baked into initdb.
# Baked, not bind-mounted, because the CI runner is docker-out-of-docker.
# Digest-pinned; compose.prod.yaml tags the built image with this same version. Refresh after a bump with:
#   docker buildx imagetools inspect mysql:<ver> --format '{{.Manifest.Digest}}'
FROM mysql:9.7.4@sha256:1f2ca53a9e94ff9f1f313099fa7dc7fb2f72e180ca5b2ef83574031e451bbbf1
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
 && rpm -K "$package" | grep -q 'signatures OK' \
 && rpm -i --nodeps --replacefiles "$package" \
 && rm -f "$package"
