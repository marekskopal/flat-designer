.PHONY: up down logs build reset-db install test test-backend test-frontend lint lint-backend lint-frontend dev

## Build and start the stack (http://localhost:$PROXY_PORT, default 4400)
up:
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
reset-db:
	docker compose down -v
	docker compose up -d --build

## Install backend + frontend dependencies on the host (for linting, tests, ng serve)
install:
	cd backend && composer install
	cd frontend && pnpm install

## Angular dev server on http://localhost:4200, proxying /api to the running stack
dev:
	cd frontend && pnpm start

test: test-backend test-frontend

test-backend:
	cd backend && vendor/bin/phpunit

test-frontend:
	cd frontend && pnpm run test

lint: lint-backend lint-frontend

lint-backend:
	cd backend && vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=1G

lint-frontend:
	cd frontend && pnpm run lint
