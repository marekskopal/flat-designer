.PHONY: up down logs build reset-db env admin install test test-backend test-frontend lint lint-backend lint-frontend dev

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

## Drop the database volume and start again; migrations re-seed the flat on boot
reset-db: env
	docker compose down -v
	docker compose up -d --build

## Create .env from .env.example and fill in a random AUTHORIZATION_TOKEN_KEY when it is empty
env:
	@if [ ! -f .env ]; then cp .env.example .env; fi
	@if ! grep -q '^AUTHORIZATION_TOKEN_KEY=.\{32,\}' .env; then \
		grep -v '^AUTHORIZATION_TOKEN_KEY=' .env > .env.tmp; \
		echo "AUTHORIZATION_TOKEN_KEY=$$(openssl rand -hex 32)" >> .env.tmp; \
		mv .env.tmp .env; \
		echo "Generated AUTHORIZATION_TOKEN_KEY in .env"; \
	fi

## Create an admin account in the running stack (prompts for email and password)
admin:
	docker compose exec backend php bin/console admin:create

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
