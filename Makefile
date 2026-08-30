.DEFAULT_GOAL := help
PROD_COMPOSE ?= docker compose -f compose.prod.yaml

.PHONY: help build up down restart status logs worker-logs test migrate seed user-admin process-reminders process-recurring-expenses process-expense-notifications process-notification-activity process-video-metadata process-video-exports process-discount-collectors video-cleanup run-discount-collector discount-scheduler-status discount-collector-runs discount-collector-run discount-collector-enable discount-collector-disable discount-collector-interval recurring-expenses-logs expense-notification-logs video-metadata-logs video-export-logs video-cleanup-logs shell worker-shell db-shell config php-version ffmpeg-version ffprobe-version video-worker-check prod-config prod-build prod-up prod-down prod-status prod-logs prod-migrate prod-seed prod-video-worker-check prod-ffmpeg-version

help:
	@echo "Mi Central - comandos disponibles"
	@echo "  make build          Construye las imagenes Docker"
	@echo "  make up             Inicia los servicios en segundo plano"
	@echo "  make down           Detiene y elimina contenedores sin borrar volumenes"
	@echo "  make restart        Reinicia el entorno sin borrar datos"
	@echo "  make status         Muestra el estado de Docker Compose"
	@echo "  make logs           Sigue los logs de Docker Compose"
	@echo "  make worker-logs    Sigue los logs del contenedor worker"
	@echo "  make test           Ejecuta las pruebas PHP actuales"
	@echo "  make migrate        Aplica migraciones pendientes"
	@echo "  make seed           Ejecuta seeds idempotentes"
	@echo "  make user-admin USERNAME=<username> Marca un usuario existente como administrador"
	@echo "  make process-reminders Ejecuta manualmente una pasada de recordatorios"
	@echo "  make process-recurring-expenses Genera gastos recurrentes pendientes"
	@echo "  make process-expense-notifications Genera notificaciones internas de gastos"
	@echo "  make process-notification-activity Ejecuta una pasada del centro general de actividad"
	@echo "  make process-video-metadata Ejecuta manualmente una pasada de metadata de video"
	@echo "  make process-video-exports Ejecuta manualmente una pasada de exportaciones de video"
	@echo "  make process-discount-collectors Ejecuta un ciclo del scheduler de recolectores"
	@echo "  make video-cleanup Ejecuta manualmente la limpieza de temporales y exports"
	@echo "  make run-discount-collector COLLECTOR=<key> [DRY_RUN=1] Ejecuta manualmente un recolector de descuentos"
	@echo "  make discount-scheduler-status Muestra estado simple del scheduler de recolectores"
	@echo "  make discount-collector-runs [COLLECTOR=<key>] Muestra ultimas ejecuciones"
	@echo "  make discount-collector-run RUN_ID=<id> Muestra detalle de una ejecucion"
	@echo "  make discount-collector-enable COLLECTOR=<key> Habilita un schedule de collector"
	@echo "  make discount-collector-disable COLLECTOR=<key> Deshabilita un schedule de collector"
	@echo "  make discount-collector-interval COLLECTOR=<key> MINUTES=<n> Cambia intervalo de collector"
	@echo "  make recurring-expenses-logs Sigue el log del worker de gastos recurrentes"
	@echo "  make expense-notification-logs Sigue el log del worker de notificaciones de gastos"
	@echo "  make video-metadata-logs Sigue el log del worker de metadata de video"
	@echo "  make video-export-logs Sigue el log del worker de exportaciones de video"
	@echo "  make video-cleanup-logs Sigue el log del worker de limpieza de video"
	@echo "  make shell          Abre una shell en el contenedor web"
	@echo "  make worker-shell   Abre una shell en el contenedor worker"
	@echo "  make db-shell       Abre el cliente MariaDB usando variables del contenedor"
	@echo "  make config         Muestra la configuracion resuelta de Docker Compose"
	@echo "  make php-version    Muestra la version PHP del contenedor web"
	@echo "  make ffmpeg-version Muestra la version FFmpeg del worker"
	@echo "  make ffprobe-version Muestra la version FFprobe del worker"
	@echo "  make video-worker-check Verifica FFmpeg, FFprobe y storage/video en el worker"
	@echo "  make prod-config    Valida la configuracion Compose de produccion"
	@echo "  make prod-build     Construye imagenes de produccion"
	@echo "  make prod-up        Inicia produccion local con compose.prod.yaml"
	@echo "  make prod-down      Detiene produccion local sin borrar volumenes"
	@echo "  make prod-status    Muestra estado de produccion local"
	@echo "  make prod-logs      Sigue logs de produccion local"
	@echo "  make prod-migrate   Aplica migraciones en produccion local"
	@echo "  make prod-seed      Ejecuta seeds en produccion local"
	@echo "  make prod-video-worker-check Verifica FFmpeg, FFprobe y storage/video en produccion"
	@echo "  make prod-ffmpeg-version Muestra la version FFmpeg de la imagen worker prod"

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

status:
	docker compose ps

logs:
	docker compose logs -f

worker-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/reminders-worker.log && tail -n 120 -f /var/www/html/storage/logs/reminders-worker.log'

video-metadata-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/video-metadata-worker.log && tail -n 120 -f /var/www/html/storage/logs/video-metadata-worker.log'

video-export-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/video-exports-worker.log && tail -n 120 -f /var/www/html/storage/logs/video-exports-worker.log'

video-cleanup-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/video-cleanup-worker.log && tail -n 120 -f /var/www/html/storage/logs/video-cleanup-worker.log'

recurring-expenses-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/recurring-expenses-worker.log && tail -n 120 -f /var/www/html/storage/logs/recurring-expenses-worker.log'

expense-notification-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/expense-notifications-worker.log && tail -n 120 -f /var/www/html/storage/logs/expense-notifications-worker.log'

test:
	docker compose exec web php tests/unit/password_hashing.php
	docker compose exec web php tests/unit/csrf.php
	docker compose exec web php tests/unit/navigation.php
	docker compose exec web php tests/unit/widget_rendering.php
	docker compose exec web php tests/unit/organization_ui_rendering.php
	docker compose exec web php tests/unit/dashboard_summary.php
	docker compose exec web php tests/unit/schedule_layout_math.php
	docker compose exec web php tests/unit/deadline_urgency.php
	docker compose exec web php tests/unit/video_upload_formdata_order.php
	docker compose exec web php tests/integration/database_connection.php
	docker compose exec web php tests/integration/auth_service.php
	docker compose exec web php tests/integration/http_auth_flow.php
	docker compose exec web php tests/integration/user_registration.php
	docker compose exec web php tests/integration/organization_schema.php
	docker compose exec web php tests/integration/organization_task_service.php
	docker compose exec web php tests/integration/organization_task_api.php
	docker compose exec web php tests/integration/organization_tasks_page.php
	docker compose exec web php tests/integration/organization_inbox.php
	docker compose exec web php tests/integration/organization_projects.php
	docker compose exec web php tests/integration/organization_notes.php
	docker compose exec web php tests/integration/organization_labels.php
	docker compose exec web php tests/integration/organization_calendar.php
	docker compose exec web php tests/integration/organization_reminders.php
	docker compose exec web php tests/integration/reminder_worker.php
	docker compose exec web php tests/integration/notifications_center.php
	docker compose exec web php tests/integration/friends_schedule.php
	docker compose exec web php tests/integration/friends_management.php
	docker compose exec web php tests/integration/friends_schedule_editor.php
	docker compose exec web php tests/integration/friends_exceptions.php
	docker compose exec web php tests/integration/friends_coincidences.php
	docker compose exec web php tests/integration/friends_coincidences_page.php
	docker compose exec web php tests/integration/friends_coincidences_organization.php
	docker compose exec web php tests/integration/friends_now_today_import.php
	docker compose exec web php tests/integration/dashboard_real_organization.php
	docker compose exec web php tests/integration/timezone_policy.php
	docker compose exec web php tests/integration/expenses_model.php
	docker compose exec web php tests/integration/expenses_configuration.php
	docker compose exec web php tests/integration/expenses_monthly.php
	docker compose exec web php tests/integration/expenses_recurring.php
	docker compose exec web php tests/integration/expenses_monthly_summary.php
	docker compose exec web php tests/integration/expenses_notifications.php
	docker compose exec web php tests/integration/expenses_history.php
	docker compose exec web php tests/integration/expenses_import.php
	docker compose exec web php tests/integration/discounts_model.php
	docker compose exec web php tests/integration/discounts_user_benefits.php
	docker compose exec web php tests/integration/discounts_promotions_crud.php
	docker compose exec web php tests/integration/discounts_compatibility.php
	docker compose exec web php tests/integration/discounts_discovery.php
	docker compose exec web php tests/integration/discount_collectors.php
	docker compose exec web php tests/integration/discount_import_pipeline.php
	docker compose exec web php tests/integration/discount_collector_scheduler.php
	docker compose exec web php tests/integration/discount_collector_runs.php
	docker compose exec web php tests/integration/discount_banco_chile_collector.php
	docker compose exec web php tests/integration/discount_santander_chile_collector.php
	docker compose exec web php tests/integration/discount_bancoestado_collector.php
	docker compose exec web php tests/integration/video_uploads.php
	docker compose exec web php tests/integration/video_streaming.php
	docker compose exec web php tests/integration/video_cut_points.php
	docker compose exec web php tests/integration/video_segments.php
	docker compose exec worker php tests/integration/video_exports.php
	docker compose exec worker php tests/integration/video_worker_environment.php
	docker compose exec worker php tests/integration/video_metadata.php

migrate:
	docker compose exec web php bin/migrate.php

seed:
	docker compose exec web php bin/seed.php

user-admin:
	@if [ -z "$(USERNAME)" ]; then \
		echo "Usage:"; \
		echo "make user-admin USERNAME=<username>"; \
		exit 2; \
	fi
	docker compose exec web php bin/user-admin.php --username="$(USERNAME)"

process-reminders:
	docker compose exec worker php workers/process-reminders.php

process-recurring-expenses:
	docker compose exec worker php workers/process-recurring-expenses.php

process-expense-notifications:
	docker compose exec worker php workers/process-expense-notifications.php

process-notification-activity:
	docker compose exec worker php workers/process-notification-activity.php

process-video-metadata:
	docker compose exec worker php workers/process-video-metadata.php

process-video-exports:
	docker compose exec worker php workers/process-video-exports.php

process-discount-collectors:
	docker compose exec worker php workers/process-discount-collectors.php

video-cleanup:
	docker compose exec worker php workers/cleanup-video-files.php

run-discount-collector:
	@if [ -z "$(COLLECTOR)" ]; then \
		echo "Usage:"; \
		echo "make run-discount-collector COLLECTOR=<collector-key> [DRY_RUN=1]"; \
		exit 2; \
	fi
	docker compose exec worker php workers/run-discount-collector.php $(COLLECTOR) $(if $(DRY_RUN),--dry-run,)

discount-scheduler-status:
	docker compose exec worker php workers/discount-collector-schedule.php status

discount-collector-runs:
	docker compose exec worker php workers/discount-collector-schedule.php runs $(if $(COLLECTOR),--collector="$(COLLECTOR)",)

discount-collector-run:
	@if [ -z "$(RUN_ID)" ]; then \
		echo "Usage:"; \
		echo "make discount-collector-run RUN_ID=<run-id>"; \
		exit 2; \
	fi
	docker compose exec worker php workers/discount-collector-schedule.php run --run-id="$(RUN_ID)"

discount-collector-enable:
	@if [ -z "$(COLLECTOR)" ]; then \
		echo "Usage:"; \
		echo "make discount-collector-enable COLLECTOR=<collector-key>"; \
		exit 2; \
	fi
	docker compose exec worker php workers/discount-collector-schedule.php enable --collector="$(COLLECTOR)"

discount-collector-disable:
	@if [ -z "$(COLLECTOR)" ]; then \
		echo "Usage:"; \
		echo "make discount-collector-disable COLLECTOR=<collector-key>"; \
		exit 2; \
	fi
	docker compose exec worker php workers/discount-collector-schedule.php disable --collector="$(COLLECTOR)"

discount-collector-interval:
	@if [ -z "$(COLLECTOR)" ] || [ -z "$(MINUTES)" ]; then \
		echo "Usage:"; \
		echo "make discount-collector-interval COLLECTOR=<collector-key> MINUTES=<minutes>"; \
		exit 2; \
	fi
	docker compose exec worker php workers/discount-collector-schedule.php interval --collector="$(COLLECTOR)" --minutes="$(MINUTES)"

shell:
	docker compose exec web sh

worker-shell:
	docker compose exec worker sh

db-shell:
	docker compose exec db sh -lc 'mariadb -u"$${MARIADB_USER}" -p"$${MARIADB_PASSWORD}" "$${MARIADB_DATABASE}"'

config:
	docker compose config

php-version:
	docker compose exec web php -v

ffmpeg-version:
	docker compose exec worker sh -lc '"$${FFMPEG_BIN:-/usr/bin/ffmpeg}" -version'

ffprobe-version:
	docker compose exec worker sh -lc '"$${FFPROBE_BIN:-/usr/bin/ffprobe}" -version'

video-worker-check:
	docker compose exec worker php workers/check-video-environment.php

prod-config:
	$(PROD_COMPOSE) config

prod-build:
	$(PROD_COMPOSE) build

prod-up:
	$(PROD_COMPOSE) up -d

prod-down:
	$(PROD_COMPOSE) down

prod-status:
	$(PROD_COMPOSE) ps

prod-logs:
	$(PROD_COMPOSE) logs -f

prod-migrate:
	$(PROD_COMPOSE) exec web php bin/migrate.php

prod-seed:
	$(PROD_COMPOSE) exec web php bin/seed.php

prod-video-worker-check:
	$(PROD_COMPOSE) exec worker php workers/check-video-environment.php

prod-ffmpeg-version:
	docker run --rm mi-central-worker:prod ffmpeg -version
