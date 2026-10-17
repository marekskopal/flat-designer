#!/bin/sh
# Apply pending migrations before serving. The flat and kitchen are seeded by migrations, so a fresh
# database is ready to view as soon as the container is healthy. MariaDB may still be finishing its
# own start-up even after its healthcheck passed, so retry for a while.
set -e

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
