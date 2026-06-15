#!/usr/bin/env bash
# Install WordPress core and the PHPUnit test suite for integration testing.
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Environment variables (optional overrides):
#   WP_TESTS_DIR   where to install the test suite  (default: /tmp/wordpress-tests-lib)
#   WP_CORE_DIR    where to install WordPress core   (default: /tmp/wordpress)

set -e

DB_NAME="${1:?Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]}"
DB_USER="${2:?}"
DB_PASS="${3:?}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

download() {
    if command -v curl >/dev/null 2>&1; then
        curl -s -o "$2" "$1"
    elif command -v wget >/dev/null 2>&1; then
        wget -nv -O "$2" "$1"
    else
        printf 'Error: neither curl nor wget found.\n' >&2
        exit 1
    fi
}

resolve_wp_version() {
    if [ "$WP_VERSION" = 'latest' ]; then
        if command -v curl >/dev/null 2>&1; then
            WP_VERSION=$(curl -s https://api.wordpress.org/core/version-check/1.7/ \
                | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
        else
            WP_VERSION=$(wget -qO- https://api.wordpress.org/core/version-check/1.7/ \
                | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
        fi
    fi
}

install_wp() {
    [ -d "$WP_CORE_DIR" ] && return
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
    tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
}

install_test_suite() {
    [ -d "$WP_TESTS_DIR" ] && return
    mkdir -p "$WP_TESTS_DIR"

    local svn_tag="tags/${WP_VERSION}"
    [ "$WP_VERSION" = 'trunk' ] && svn_tag="trunk"

    svn co --quiet --ignore-externals \
        "https://develop.svn.wordpress.org/${svn_tag}/tests/phpunit/includes/" \
        "$WP_TESTS_DIR/includes"
    svn co --quiet --ignore-externals \
        "https://develop.svn.wordpress.org/${svn_tag}/tests/phpunit/data/" \
        "$WP_TESTS_DIR/data"

    download \
        "https://develop.svn.wordpress.org/${svn_tag}/wp-tests-config-sample.php" \
        "$WP_TESTS_DIR/wp-tests-config.php"

    # Substitute placeholders — write via temp file to stay portable across
    # GNU sed (-i) and BSD sed (-i ''), which differ in in-place semantics.
    _sedi() { sed "$1" "$2" > /tmp/_wptc && mv /tmp/_wptc "$2"; }
    _sedi "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
    _sedi "s/youremptytestdbnamehere/${DB_NAME}/"              "$WP_TESTS_DIR/wp-tests-config.php"
    _sedi "s/yourusernamehere/${DB_USER}/"                     "$WP_TESTS_DIR/wp-tests-config.php"
    _sedi "s/yourpasswordhere/${DB_PASS}/"                     "$WP_TESTS_DIR/wp-tests-config.php"
    _sedi "s|localhost|${DB_HOST}|"                            "$WP_TESTS_DIR/wp-tests-config.php"
}

wait_for_mysql() {
    local retries=30
    until mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "SELECT 1" >/dev/null 2>&1; do
        retries=$((retries - 1))
        if [ "$retries" -le 0 ]; then
            printf 'Error: MySQL not available after 30 attempts.\n' >&2
            exit 1
        fi
        sleep 2
    done
}

create_db() {
    mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" \
        -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
}

resolve_wp_version
install_wp
install_test_suite
wait_for_mysql
create_db
