# Container interaction helpers. COMPOSE auto-detects the stack so the same
# `make <target>` works in both places: prod compose on the homelab (where the
# host secrets file /opt/teman-lari/.env exists), dev compose otherwise.
# Override with: make COMPOSE="docker compose -f compose.yaml" <target>
#   Dev shortcuts (up/down/test/pint/stan) delegate to Laravel Sail.
#   Note: prod-only targets (logs-horizon, logs-pulse, health) have no dev equivalent.

COMPOSE ?= docker compose -f $(if $(wildcard /opt/teman-lari/.env),compose.prod.yaml,compose.yaml)

.DEFAULT_GOAL := help
.PHONY: help ps logs logs-app logs-horizon logs-pulse tail shell tinker artisan \
        health pulse-restart pulse-clear restart up down test pint stan

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---- Prod / homelab (COMPOSE=compose.prod.yaml) ----

ps: ## List prod containers + state
	$(COMPOSE) ps

logs: ## Tail all prod logs (follow)
	$(COMPOSE) logs -f --tail=200

logs-app: ## Tail the app container
	$(COMPOSE) logs -f --tail=200 app

logs-horizon: ## Tail the Horizon worker
	$(COMPOSE) logs -f --tail=200 horizon

logs-pulse: ## Tail the Pulse daemons (check + work + redis)
	$(COMPOSE) logs -f --tail=200 pulse-check pulse-work pulse-redis

tail: ## Tail the persisted daily log file (survives deploys via app_logs)
	$(COMPOSE) exec app sh -c 'tail -f storage/logs/laravel-$$(date +%Y-%m-%d).log'

shell: ## Open a shell in the app container
	$(COMPOSE) exec app bash

tinker: ## Open tinker in the app container
	$(COMPOSE) exec app php artisan tinker

artisan: ## Run an artisan command, e.g. make artisan cmd="route:list"
	$(COMPOSE) exec app php artisan $(cmd)

health: ## Hit /up from inside the app container (deps-aware healthcheck)
	$(COMPOSE) exec app wget -qO- http://127.0.0.1:7001/up && echo " OK"

pulse-restart: ## Gracefully restart the Pulse daemons (pick up new code)
	$(COMPOSE) exec app php artisan pulse:restart

pulse-clear: ## Wipe all stored Pulse data
	$(COMPOSE) exec app php artisan pulse:clear

restart: ## Recreate prod containers with the current image
	$(COMPOSE) up -d

# ---- Dev (Sail) ----

up: ## Start the dev stack
	./vendor/bin/sail up -d

down: ## Stop the dev stack
	./vendor/bin/sail down

test: ## Run the test suite (parallel)
	./vendor/bin/sail pest --parallel

pint: ## Format PHP (Pint)
	./vendor/bin/sail bin pint

stan: ## Run PHPStan
	./vendor/bin/sail bin phpstan analyse
