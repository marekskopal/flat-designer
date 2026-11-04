#!/bin/sh
# Apply pending migrations before serving. The flat and kitchen are seeded by migrations, so a fresh
# database is ready to view as soon as the container is healthy. MariaDB may still be finishing its
# own start-up even after its healthcheck passed, so retry for a while.
set -e

# The Mercure hub (Caddyfile) and the backend's tokens must agree on the resource identifier.
if [ -z "${MERCURE_RESOURCE_IDENTIFIER:-}" ]; then
	export MERCURE_RESOURCE_IDENTIFIER="${MERCURE_PUBLISH_URL:-http://localhost/.well-known/mercure}"
fi

# The hub's CORS origins follow the API's. The Caddyfile cannot take an empty list, and with none allowed (the
# default: the app subscribes from its own origin) it gets one that never matches (.invalid is a reserved name).
export MERCURE_CORS_ORIGINS="${BACKEND_CORS_ALLOWED_ORIGIN:-http://cors.invalid}"

attempt=1
until php /app/bin/console migration:run; do
	if [ "$attempt" -ge 30 ]; then
		echo "Migrations failed after $attempt attempts." >&2
		exit 1
	fi
	attempt=$((attempt + 1))
	sleep 2
done

exec "$@"
