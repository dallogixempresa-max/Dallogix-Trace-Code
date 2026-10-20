FROM container-registry.oracle.com/mysql/community-server:8.4.12@sha256:7dcc4add9183664de3a214daf85a50c3ba6cccfd7534f700b6561bf5b41885be

# The Trace database uses mysqld and the mysql CLI, not the optional mysqlsh.
# Apply Oracle Linux security updates during image build (never at container
# startup), because the upstream 8.4.12 digest can lag behind package fixes.
# Keep the UID/GID used by the previous official mysql:8.4 image so an existing
# named data volume remains readable when Compose replaces the image.
RUN microdnf update --assumeyes \
    && microdnf remove --assumeyes mysql-shell \
    && microdnf clean all \
    && groupmod --gid 999 mysql \
    && usermod --uid 999 --gid 999 mysql \
    && chown -R mysql:mysql /var/run/mysqld /var/lib/mysql-files /var/lib/mysql-keyring

# The upstream entrypoint supports non-root initialization; keep the same UID
# as existing data volumes while preventing the container from starting as root.
USER mysql
