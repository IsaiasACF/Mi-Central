.DEFAULT_GOAL := help
MODEL ?= base

.PHONY: help build up down restart status logs worker-logs test migrate seed process-reminders process-video-metadata process-video-exports process-video-transcriptions video-cleanup video-metadata-logs video-export-logs video-transcription-logs video-cleanup-logs shell worker-shell db-shell config php-version ffmpeg-version ffprobe-version whisper-version video-worker-check whisper-model transcription-check

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
	@echo "  make process-reminders Ejecuta manualmente una pasada de recordatorios"
	@echo "  make process-video-metadata Ejecuta manualmente una pasada de metadata de video"
	@echo "  make process-video-exports Ejecuta manualmente una pasada de exportaciones de video"
	@echo "  make process-video-transcriptions Ejecuta manualmente una pasada de transcripciones de video"
	@echo "  make video-cleanup Ejecuta manualmente la limpieza de temporales y exports"
	@echo "  make video-metadata-logs Sigue el log del worker de metadata de video"
	@echo "  make video-export-logs Sigue el log del worker de exportaciones de video"
	@echo "  make video-transcription-logs Sigue el log del worker de transcripciones de video"
	@echo "  make video-cleanup-logs Sigue el log del worker de limpieza de video"
	@echo "  make shell          Abre una shell en el contenedor web"
	@echo "  make worker-shell   Abre una shell en el contenedor worker"
	@echo "  make db-shell       Abre el cliente MariaDB usando variables del contenedor"
	@echo "  make config         Muestra la configuracion resuelta de Docker Compose"
	@echo "  make php-version    Muestra la version PHP del contenedor web"
	@echo "  make ffmpeg-version Muestra la version FFmpeg del worker"
	@echo "  make ffprobe-version Muestra la version FFprobe del worker"
	@echo "  make whisper-version Comprueba el binario whisper.cpp del worker"
	@echo "  make whisper-model MODEL=base Descarga un modelo Whisper local si no existe"
	@echo "  make video-worker-check Verifica FFmpeg, FFprobe y storage/video en el worker"
	@echo "  make transcription-check Verifica whisper.cpp, modelo y storage de transcripcion"

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

video-transcription-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/video-transcriptions-worker.log && tail -n 120 -f /var/www/html/storage/logs/video-transcriptions-worker.log'

video-cleanup-logs:
	docker compose exec worker sh -lc 'touch /var/www/html/storage/logs/video-cleanup-worker.log && tail -n 120 -f /var/www/html/storage/logs/video-cleanup-worker.log'

test:
	docker compose exec web php tests/unit/password_hashing.php
	docker compose exec web php tests/unit/csrf.php
	docker compose exec web php tests/unit/navigation.php
	docker compose exec web php tests/unit/widget_rendering.php
	docker compose exec web php tests/unit/dashboard_summary.php
	docker compose exec web php tests/unit/schedule_layout_math.php
	docker compose exec web php tests/unit/video_upload_formdata_order.php
	docker compose exec web php tests/unit/video_transcription_parser.php
	docker compose exec web php tests/integration/database_connection.php
	docker compose exec web php tests/integration/auth_service.php
	docker compose exec web php tests/integration/http_auth_flow.php
	docker compose exec web php tests/integration/organization_schema.php
	docker compose exec web php tests/integration/organization_task_service.php
	docker compose exec web php tests/integration/organization_task_api.php
	docker compose exec web php tests/integration/organization_tasks_page.php
	docker compose exec web php tests/integration/organization_inbox.php
	docker compose exec web php tests/integration/organization_projects.php
	docker compose exec web php tests/integration/organization_notes.php
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
	docker compose exec web php tests/integration/video_uploads.php
	docker compose exec web php tests/integration/video_streaming.php
	docker compose exec web php tests/integration/video_cut_points.php
	docker compose exec web php tests/integration/video_segments.php
	docker compose exec worker php tests/integration/video_exports.php
	docker compose exec worker php tests/integration/video_worker_environment.php
	docker compose exec worker php tests/integration/video_metadata.php
	docker compose exec worker php tests/integration/video_transcription_infrastructure.php
	docker compose exec worker php tests/integration/video_transcriptions.php
	docker compose exec web php tests/integration/video_transcription_exports.php

migrate:
	docker compose exec web php bin/migrate.php

seed:
	docker compose exec web php bin/seed.php

process-reminders:
	docker compose exec worker php workers/process-reminders.php

process-video-metadata:
	docker compose exec worker php workers/process-video-metadata.php

process-video-exports:
	docker compose exec worker php workers/process-video-exports.php

process-video-transcriptions:
	docker compose exec worker php workers/process-video-transcriptions.php

video-cleanup:
	docker compose exec worker php workers/cleanup-video-files.php

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

whisper-version:
	docker compose exec worker sh -lc '"$${WHISPER_BIN:-/usr/local/bin/whisper-cli}" --help'

video-worker-check:
	docker compose exec worker php workers/check-video-environment.php

whisper-model:
	docker compose exec worker php workers/install-whisper-model.php $(MODEL)

transcription-check:
	docker compose exec worker php workers/check-transcription-environment.php
