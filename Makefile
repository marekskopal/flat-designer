.PHONY: up deploy down logs build reset-db migrate-volumes env ssl-cert admin reset-password backup restore import-files import-models example-models screenshots smoke benchmark install test test-backend test-frontend lint lint-backend lint-frontend dev

# Compose variables (database credentials for the test run); `make env` creates the file.
-include .env

## Build and start the stack (http://localhost:$PROXY_PORT, default 4400)
up: env
	docker compose up -d --build

## Start the stack from the published images of IZBIK_VERSION (.env) instead of building them; the way to install and
## upgrade a release (see docs/self-hosting.md). The images serve the app at the root (APP_PATH empty).
deploy: env
	docker compose pull proxy frontend backend
	docker compose up -d --no-build --remove-orphans

## Stop the stack
down:
	docker compose down

## Tail logs
logs:
	docker compose logs -f

## Rebuild images without cache
build:
	docker compose build --no-cache

## Drop the database and file volumes and start again; migrations re-seed the flat on boot
reset-db: env
	docker compose down -v
	docker compose up -d --build

## Copy the database and file volumes of the old "flat-designer" compose project into the Izbik ones (run once, stack down)
migrate-volumes:
	@for pair in flat_designer_data:izbik_data flat_designer_files:izbik_files; do \
		from=flat-designer_$${pair%%:*}; to=izbik_$${pair#*:}; \
		if ! docker volume inspect $$from >/dev/null 2>&1; then echo "No $$from, skipped"; continue; fi; \
		if docker volume inspect $$to >/dev/null 2>&1; then echo "$$to exists, skipped"; continue; fi; \
		docker volume create $$to >/dev/null; \
		docker run --rm -v $$from:/from -v $$to:/to alpine cp -a /from/. /to/; \
		echo "Copied $$from to $$to"; \
	done

## Create .env from .env.example and fill in random secrets (token and Mercure keys) that are empty. A new .env also
## gets random database and MinIO passwords instead of the examples' (only then: the database keeps the ones it started with)
env:
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		for key in MYSQL_PASSWORD MYSQL_ROOT_PASSWORD S3_SECRET_KEY; do \
			grep -v "^$$key=" .env > .env.tmp; \
			echo "$$key=$$(openssl rand -hex 24)" >> .env.tmp; \
			mv .env.tmp .env; \
		done; \
		echo "Created .env with random database and storage passwords"; \
	fi
	@for key in AUTHORIZATION_TOKEN_KEY MERCURE_PUBLISHER_JWT_KEY MERCURE_SUBSCRIBER_JWT_KEY; do \
		if ! grep -q "^$$key=.\{32,\}" .env; then \
			grep -v "^$$key=" .env > .env.tmp; \
			echo "$$key=$$(openssl rand -hex 32)" >> .env.tmp; \
			mv .env.tmp .env; \
			echo "Generated $$key in .env"; \
		fi; \
	done

## Self-signed certificate for https://localhost in ssl/, set as PROXY_SSL_CERT/KEY in .env
ssl-cert: env
	@mkdir -p ssl
	@if [ ! -f ssl/server.pem ]; then \
		openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj '/CN=localhost' \
			-addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
			-keyout ssl/server.key -out ssl/server.pem 2>/dev/null; \
		echo "Generated ssl/server.pem"; \
	fi
	@for pair in PROXY_SSL_CERT=ssl/server.pem PROXY_SSL_KEY=ssl/server.key; do \
		key=$${pair%%=*}; \
		grep -v "^$$key=" .env > .env.tmp; \
		echo "$$key=$(CURDIR)/$${pair#*=}" >> .env.tmp; \
		mv .env.tmp .env; \
	done

## Create an admin account in the running stack (prompts for email and password)
admin:
	docker compose exec backend php bin/console admin:create

## Set a user's password in the running stack, for a server that sends no mail (prompts): make reset-password EMAIL=…
reset-password:
	docker compose exec backend php bin/console user:reset-password "$(EMAIL)"

## Back up the database (the bundled MariaDB) and every stored file (MinIO or S3) into backups/<date-time>/, or BACKUP=…
## (the stack must be running; the app keeps running meanwhile): make backup [BACKUP=backups/before-upgrade]
backup:
	@dir="$(or $(BACKUP),backups/$(shell date +%Y%m%d-%H%M%S))"; \
	mkdir -p "$$dir/files" && \
	docker compose exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mariadb-dump -uroot --single-transaction --routines --triggers --add-drop-database --databases "$$MYSQL_DATABASE"' > "$$dir/database.sql" && \
	gzip "$$dir/database.sql" && \
	docker compose run --rm --no-deps --entrypoint "" -u "$$(id -u):$$(id -g)" -v "$$(cd "$$dir/files" && pwd)":/backup backend php bin/console storage:backup /backup && \
	echo "Backed up to $$dir"

## Restore a backup of make backup: replaces the database and puts the stored files back, with the backend stopped
## meanwhile (it migrates the restored database when it starts): make restore BACKUP=backups/<date-time> (asks first)
restore:
	@[ -n "$(BACKUP)" ] && [ -f "$(BACKUP)/database.sql.gz" ] && [ -f "$(BACKUP)/files/objects.json" ] || { echo "Usage: make restore BACKUP=backups/<date-time> (a directory of make backup)"; exit 1; }
	@gunzip -t "$(BACKUP)/database.sql.gz"
	@printf 'This replaces the database and the stored files with the backup %s. Type yes to go on: ' "$(BACKUP)"; read answer; [ "$$answer" = yes ]
	docker compose stop backend
	gunzip -c "$(BACKUP)/database.sql.gz" | docker compose exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mariadb -uroot'
	docker compose run --rm --no-deps --entrypoint "" -v "$(abspath $(BACKUP))/files":/backup:ro backend php bin/console storage:restore /backup
	docker compose start backend

## Upload a directory to a project as its files: make import-files DIR=source_files PROJECT=1
import-files:
	docker compose run --rm --no-deps -v "$(abspath $(DIR))":/import:ro backend php bin/console files:import /import --project=$(PROJECT)

## Upload the .glb models of a directory (with optional <name>.json credits): make import-models DIR=… [PROJECT=…]
import-models:
	docker compose run --rm --no-deps -v "$(abspath $(DIR))":/import:ro backend php bin/console models:import /import $(if $(PROJECT),--project=$(PROJECT))

## Download the product models of the example projects from Poly Haven (CC0) into data/example-models (gitignored),
## optimise them and import them as global models (needs the running stack; models already there are skipped)
example-models:
	docker run --rm -v "$(CURDIR)":/work -w /work/frontend -u "$$(id -u):$$(id -g)" -e HOME=/tmp \
		node:24.22.2-alpine node scripts/example-models.mjs /work/data/example-models /work/backend/examples
	$(MAKE) import-models DIR=data/example-models

## Capture the README screenshots of a project's saved views and pages into docs/screenshots (needs the running stack):
## make screenshots PROJECT=4 EMAIL=you@example.com PASSWORD=… [VIEWS="Tour ·"] [VIEWS_ONLY=1] [ROOM=Kitchen] [URL=http://localhost:4400]
## (EMAIL and PASSWORD default to SCREENSHOTS_EMAIL / SCREENSHOTS_PASSWORD from .env, as for make benchmark)
## (VIEWS: only the saved views whose name starts with it; VIEWS_ONLY=1: not the editor and the pages; ROOM: the room
## shown in the editor shot; WebP with cwebp)
screenshots:
	@cd frontend && pnpm exec playwright install chromium >/dev/null && \
		FD_PROJECT=$(PROJECT) FD_EMAIL='$(or $(EMAIL),$(SCREENSHOTS_EMAIL))' FD_PASSWORD='$(or $(PASSWORD),$(SCREENSHOTS_PASSWORD))' FD_VIEWS="$(VIEWS)" FD_VIEWS_ONLY="$(VIEWS_ONLY)" FD_ROOM="$(ROOM)" \
		FD_URL=$(or $(URL),http://localhost:$(or $(PROXY_PORT),4400)) FD_APP_PATH=$(APP_PATH) node scripts/screenshots.mjs

## End-to-end smoke test of the running stack through the browser (needs SIGN_UP_ENABLED=1): sign up, a project with
## walls, a room and a door, a share link, the example (FD_SOFTWARE_GL=1 without a GPU, FD_HEADED=1 to watch)
smoke:
	@cd frontend && pnpm exec playwright install chromium >/dev/null && \
		FD_URL=$(or $(URL),http://localhost:$(or $(PROXY_PORT),4400)) FD_APP_PATH=$(APP_PATH) node scripts/smoke.mjs

## Measure the rendering cost of a project's saved views into frontend/benchmarks (needs the running stack):
## make benchmark PROJECT=5 EMAIL=you@example.com PASSWORD=… [VIEWS="Tour ·"] [SCENARIOS=fast-day,…] [FRAMES=120] [RUNS=3]
## [BASELINE=frontend/benchmarks/….json] (compare results from the same machine only)
benchmark:
	@cd frontend && pnpm exec playwright install chromium >/dev/null && \
		FD_PROJECT=$(PROJECT) FD_EMAIL='$(or $(EMAIL),$(SCREENSHOTS_EMAIL))' FD_PASSWORD='$(or $(PASSWORD),$(SCREENSHOTS_PASSWORD))' FD_VIEWS="$(VIEWS)" FD_SCENARIOS="$(SCENARIOS)" \
		FD_FRAMES=$(FRAMES) FD_RUNS=$(RUNS) FD_BASELINE=$(if $(BASELINE),$(abspath $(BASELINE))) \
		FD_URL=$(or $(URL),http://localhost:$(or $(PROXY_PORT),4400)) FD_APP_PATH=$(APP_PATH) node scripts/benchmark.mjs

## Install backend + frontend dependencies on the host (for linting, tests, ng serve)
install:
	cd backend && composer install
	cd frontend && pnpm install

## Angular dev server on http://localhost:4200, proxying /api to the running stack
dev:
	cd frontend && pnpm start

test: test-backend test-frontend

## Backend tests run in a one-off backend container (host vendor/ bind-mounted) against the stack's
## MariaDB, in a separate `izbik_test` database created as root.
test-backend:
	docker compose run --rm --no-deps --entrypoint "" -v ./backend:/app \
		-e MYSQL_USER=root -e MYSQL_PASSWORD=$(MYSQL_ROOT_PASSWORD) -e TEST_MYSQL_DATABASE=izbik_test \
		backend vendor/bin/phpunit

test-frontend:
	cd frontend && pnpm run test

lint: lint-backend lint-frontend

lint-backend:
	cd backend && vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=1G

lint-frontend:
	cd frontend && pnpm run lint
