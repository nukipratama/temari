# Container interaction helpers.
#   Prod targets drive the homelab stack (compose.prod.yaml).
#   Dev shortcuts delegate to Laravel Sail.
# Override the compose file with: make COMPOSE="docker compose -f compose.yaml" <target>

COMPOSE ?= docker compose -f compose.prod.yaml
ANALYTICS_DB ?= teman_lari_analytics

.DEFAULT_GOAL := help
.PHONY: help ps logs logs-app logs-horizon logs-pulse tail shell tinker artisan \
        health pulse-restart pulse-clear analytics-init restart up down test pint stan

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

analytics-init: ## One-time: create analytics schema + grant, migrate, backfill from main
	@echo ">> create schema + grant (reuses the initdb script)"
	$(COMPOSE) exec -T mysql sh /docker-entrypoint-initdb.d/01-analytics-db.sh
	@echo ">> migrate analytics schema"
	$(COMPOSE) exec -T app php artisan migrate --database=analytics --path=database/migrations/analytics --force
	@echo ">> backfill existing usage rows (INSERT IGNORE; safe to re-run)"
	$(COMPOSE) exec -T mysql sh -c 'mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "INSERT IGNORE INTO $(ANALYTICS_DB).ai_token_usages (id,user_id,kind,prompt_tokens,completion_tokens,total_tokens,latency_ms,truncated,model,created_at) SELECT id,user_id,kind,prompt_tokens,completion_tokens,total_tokens,latency_ms,truncated,model,created_at FROM $$MYSQL_DATABASE.ai_token_usages;"' \
		|| echo "   (no source table — fresh install, nothing to backfill)"
	@echo ">> row counts (drop the orphan main table only once these match):"
	$(COMPOSE) exec -T mysql sh -c 'mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "SELECT (SELECT COUNT(*) FROM $$MYSQL_DATABASE.ai_token_usages) AS main_rows, (SELECT COUNT(*) FROM $(ANALYTICS_DB).ai_token_usages) AS analytics_rows;"' \
		|| true

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
