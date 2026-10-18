#!/bin/sh
# Pick the nginx config template by PROXY_SSL_ENABLED. Runs before nginx's 20-envsubst-on-templates.sh,
# which renders /etc/nginx/templates/default.conf.template into /etc/nginx/conf.d/default.conf.
set -eu

SOURCE_DIR=/etc/nginx/conf-available
TARGET=/etc/nginx/templates/default.conf.template

mkdir -p "$(dirname "$TARGET")"

if [ "${PROXY_SSL_ENABLED:-0}" = "1" ]; then
    cp "${SOURCE_DIR}/ssl.conf.template" "${TARGET}"
else
    cp "${SOURCE_DIR}/http.conf.template" "${TARGET}"
fi
