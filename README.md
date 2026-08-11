# Mi Central

Mi Central es una aplicacion web privada y personal para agrupar pequenas herramientas y modulos de organizacion.

## Estado actual

El proyecto cuenta con entorno Docker Compose, autenticacion base, Dashboard autenticado y el modulo Organizacion con tareas, proyectos, subtareas, notas, calendario y recordatorios.

Los recordatorios soportan recurrencias simples y un worker Cron genera notificaciones internas pendientes cuando vencen. Aun no existe centro visual completo de notificaciones, Push, PWA ni envio por email.

## Stack general

- PHP 8.5
- Apache
- MariaDB 11.8
- HTML5
- CSS3
- JavaScript nativo
- FFmpeg
- Docker Compose
- PHP CLI
- Cron

## Entorno

La terminal principal de trabajo es **WSL/Linux** sobre un equipo anfitrion con Windows 11.

El proyecto se ejecuta mediante Docker Compose.

## Contexto del proyecto

La referencia principal del proyecto esta en `docs/PROJECT_CONTEXT.md`.

Mi Central es un proyecto independiente y no debe reutilizar arquitectura, configuraciones ni codigo de otros proyectos salvo que aparezcan explicitamente dentro de este repositorio.

## Desarrollo local con WSL + Docker

Desde WSL, entrar al directorio del proyecto:

```sh
cd "/mnt/c/Users/icart/OneDrive/Escritorio/My Space"
```

Crear el archivo local de entorno:

```sh
cp .env.example .env
```

Completar en `.env` contrasenas locales para:

- `DB_PASSWORD`
- `DB_ROOT_PASSWORD`

El `Makefile` es una capa de conveniencia para comandos habituales. Docker Compose sigue siendo la infraestructura real del proyecto y los comandos equivalentes se conservan como referencia.

Construir y levantar los servicios:

```sh
make build
make up
```

Aplicar migraciones:

```sh
make migrate
```

Ejecutar seeds idempotentes:

```sh
make seed
```

Crear manualmente el usuario propietario inicial:

```sh
docker compose exec web php bin/create-user.php
```

Verificar los contenedores:

```sh
make status
```

Comprobar la conexion de PHP con MariaDB desde el contenedor web:

```sh
docker compose exec web php tests/integration/database_connection.php
```

Ejecutar las pruebas PHP actuales:

```sh
make test
```

Seguir logs:

```sh
make logs
```

Seguir logs del contenedor worker:

```sh
make worker-logs
```

Ejecutar manualmente una pasada del procesador de recordatorios:

```sh
make process-reminders
```

Verificar la infraestructura local de video dentro del worker:

```sh
make ffmpeg-version
make ffprobe-version
make video-worker-check
```

El worker ejecuta `workers/process-reminders.php` cada minuto mediante Cron dentro del contenedor `worker`. La salida del procesador se guarda en:

```text
storage/logs/reminders-worker.log
```

Abrir en el navegador:

```text
http://localhost:8080
```

Detener el entorno:

```sh
make down
```

Comandos Docker Compose equivalentes:

```sh
docker compose build
docker compose up -d
docker compose exec web php bin/migrate.php
docker compose exec web php bin/seed.php
docker compose ps
docker compose logs -f
docker compose exec worker php workers/process-reminders.php
docker compose exec worker php workers/check-video-environment.php
docker compose down
```
