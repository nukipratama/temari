# Container interaction helpers. COMPOSE auto-detects the stack so the same
# `make <target>` works in both places: prod compose on the homelab (where the
# host secrets file /opt/teman-lari/.env exists), dev compose otherwise.
# Override with: make COMPOSE="docker compose -f compose.yaml" <target>
#   Prod/homelab-only helpers. For dev use ./vendor/bin/sail directly.

COMPOSE ?= docker compose -f $(if $(wildcard /opt/teman-lari/.env),compose.prod.yaml,compose.yaml)

.DEFAULT_GOAL := help
.PHONY: help ps logs logs-app logs-horizon logs-pulse tail shell tinker \
        health pulse-restart pulse-clear restart strava-doctor strava-webhook

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
	$(COMPOSE) exec app sh

tinker: ## Open tinker in the app container
	$(COMPOSE) exec app php artisan tinker

health: ## Hit /up from inside the app container (deps-aware healthcheck)
	$(COMPOSE) exec app wget -qO- http://127.0.0.1:7001/up && echo " OK"

pulse-restart: ## Gracefully restart the Pulse daemons (pick up new code)
	$(COMPOSE) exec app php artisan pulse:restart

pulse-clear: ## Wipe all stored Pulse data
	$(COMPOSE) exec app php artisan pulse:clear

restart: ## Recreate prod containers with the current image
	$(COMPOSE) up -d

strava-doctor: ## Strava health snapshot, e.g. make strava-doctor ARGS="--repair --user=1"
	$(COMPOSE) exec app php artisan strava:doctor $(ARGS)

strava-webhook: ## Manage Strava webhook subscription, e.g. make strava-webhook ARGS="--action=view"
	$(COMPOSE) exec app php artisan strava:webhook-subscribe $(ARGS)
