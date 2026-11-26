#!/bin/sh
set -eu

ENV_FILE="${COLLECTOR_ENV_FILE:-/etc/ondulateur/collector.env}"
if [ ! -r "$ENV_FILE" ]; then
    echo "Fichier de configuration absent ou illisible: $ENV_FILE" >&2
    exit 1
fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

set -a
. "$ENV_FILE"
set +a

exec /usr/bin/php "$SCRIPT_DIR/collect.php"
