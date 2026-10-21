FROM mysql:8.4.11@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364

RUN set -eux; \
    sed -i 's/^enabled=1$/enabled=0/' /etc/yum.repos.d/mysql-community-minimal.repo; \
    printf '%s\n' \
        '[mysql84-community]' \
        'name=MySQL 8.4 Community' \
        'baseurl=https://repo.mysql.com/yum/mysql-8.4-community/el/9/$basearch/' \
        'enabled=1' \
        'gpgcheck=1' \
        'gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-mysql' \
        'module_hotfixes=true' \
        > /etc/yum.repos.d/mysql-community.repo; \
    rpm -e --nodeps mysql-community-server-minimal; \
    microdnf update --assumeyes; \
    microdnf install --assumeyes --setopt=install_weak_deps=0 mysql-community-server-8.4.13-1.el9; \
    microdnf clean all; \
    test "$(rpm -q --qf '%{VERSION}-%{RELEASE}' mysql-community-server)" = '8.4.13-1.el9'; \
    sed -i '/^[[:space:]]*log-error[[:space:]]*=/d' /etc/my.cnf; \
    ! grep -Eq '^[[:space:]]*log-error[[:space:]]*=' /etc/my.cnf; \
    test "$(id -u mysql)" = '999'; \
    test "$(id -g mysql)" = '999'; \
    mkdir -p /var/lib/mysql /var/lib/mysql-files /var/lib/mysql-keyring /var/run/mysqld /etc/mysql/conf.d; \
    chown -R mysql:mysql /var/lib/mysql /var/lib/mysql-files /var/lib/mysql-keyring /var/run/mysqld

USER mysql

ENV MYSQL_VERSION=8.4.13-1.el9

VOLUME /var/lib/mysql
EXPOSE 3306 33060
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["mysqld"]
