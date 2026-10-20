.PHONY: up down logs build reset-db env ssl-cert admin import-files import-models screenshots install test test-backend test-frontend lint lint-backend lint-frontend dev

# Compose variables (database credentials for the test run); `make env` creates the file.
-include .env

## Build and start the stack (http://localhost:$PROXY_PORT, default 4400)
up: env
	docker compose up -d --build

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

## Create .env from .env.example and fill in random secrets (token and Mercure keys) that are empty
env:
	@if [ ! -f .env ]; then cp .env.example .env; fi
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

## Upload a directory to a project as its files: make import-files DIR=source_files PROJECT=1
import-files:
	docker compose run --rm --no-deps -v "$(abspath $(DIR))":/import:ro backend php bin/console files:import /import --project=$(PROJECT)

## Upload the .glb models of a directory (with optional <name>.json credits): make import-models DIR=… [PROJECT=…]
import-models:
	docker compose run --rm --no-deps -v "$(abspath $(DIR))":/import:ro backend php bin/console models:import /import $(if $(PROJECT),--project=$(PROJECT))

## Capture the README screenshots of a project's saved views and pages into docs/screenshots (needs the running stack):
## make screenshots PROJECT=4 EMAIL=you@example.com PASSWORD=… [VIEWS="Tour ·"] [ROOM=Kitchen] [URL=http://localhost:4400]
## (VIEWS: only the saved views whose name starts with it; ROOM: the room shown in the editor shot; WebP with cwebp)
screenshots:
	cd frontend && pnpm exec playwright install chromium >/dev/null && \
		FD_PROJECT=$(PROJECT) FD_EMAIL=$(EMAIL) FD_PASSWORD=$(PASSWORD) FD_VIEWS="$(VIEWS)" FD_ROOM="$(ROOM)" \
		FD_URL=$(or $(URL),http://localhost:$(or $(PROXY_PORT),4400)) node scripts/screenshots.mjs

## Install backend + frontend dependencies on the host (for linting, tests, ng serve)
install:
	cd backend && composer install
	cd frontend && pnpm install

## Angular dev server on http://localhost:4200, proxying /api to the running stack
dev:
	cd frontend && pnpm start

test: test-backend test-frontend

## Backend tests run in a one-off backend container (host vendor/ bind-mounted) against the stack's
## MariaDB, in a separate `flat_designer_test` database created as root.
test-backend:
	docker compose run --rm --no-deps --entrypoint "" -v ./backend:/app \
		-e MYSQL_USER=root -e MYSQL_PASSWORD=$(MYSQL_ROOT_PASSWORD) -e TEST_MYSQL_DATABASE=flat_designer_test \
		backend vendor/bin/phpunit

test-frontend:
	cd frontend && pnpm run test

lint: lint-backend lint-frontend

lint-backend:
	cd backend && vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=1G

lint-frontend:
	cd frontend && pnpm run lint
