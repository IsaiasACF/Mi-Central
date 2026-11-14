# Consultas y memoria provisionada en Vercel

Revision: 2026-11-14. El aviso de 263% de 360 GB-h corresponde al equipo completo; no atribuye por si solo ese consumo a Mi Central. No se calculan ahorros en GB-h sin datos de produccion.

## Base de comparacion

El repositorio local estaba limpio, en `master`, commit `3f438bb`. Ese commit ya tenia una reduccion parcial a 300 segundos y comprobacion de visibilidad. Se consulto GitHub: `IsaiasACF/My-Space` redirige a `IsaiasACF/Mi-Central` (mismo ID de repositorio). `origin/main`, consultado durante el trabajo, era `3556954`; la unica diferencia de contenido respecto del HEAD local estaba en el ciclo de Notificaciones: `setInterval(refreshAll, 60000)`, sin comprobacion de visibilidad.

Las cifras anteriores de la tabla son **estimaciones derivadas del codigo de main**, no mediciones de la version anterior ejecutada. Las cifras posteriores se verificaron con Chrome headless y los scripts reales de la aplicacion, DOM real, reloj controlado y fetch simulado. No representan trafico real ni una carga de usuarios concurrentes sobre Vercel. Se excluyen HTML, acciones manuales, cargas iniciales y tiempo de respuesta salvo donde se indica. Un timer del navegador puede retrasarse en segundo plano; la tabla anterior representa los ciclos programados por el codigo.

| Escenario | Antes: main | Despues: verificado |
| --- | --- | --- |
| Otra pantalla, campana cerrada, 10 minutos visibles | 10 GET de notificaciones | 0 GET |
| Abrir campana | 1 GET, ademas del timer; podia solaparse | 1 GET `limit=5`; aperturas repetidas pendientes comparten peticion |
| Notificaciones visibles, 10 minutos | 20 GET (cabecera + vista por minuto) | 2 GET, uno cada 5 minutos, `status=all/unread/read&limit=50` |
| Notificaciones ocultas, 10 minutos | 20 GET programados | 0 GET nuevos |
| Otra pantalla oculta, 10 minutos | 10 GET programados | 0 GET nuevos |
| Volver visible con datos frescos (<60 s) | No habia manejador en main | 0 GET de notificaciones |
| Volver visible con datos antiguos | Dependia del siguiente timer | 1 GET; en Notificaciones consulta la vista y reutiliza contador |
| Abrir campana durante GET de pagina sin filtro | Podia agregar otra peticion | Comparte el GET pendiente y sus primeros 5 elementos |
| Marcar una/todas como leidas en Notificaciones | 1 POST + 2 GET de refresco | 1 POST + 0 GET de refresco; UI y contador cambian al confirmar exito |
| Marcar una/todas desde otra pantalla | 1 POST + 1 GET | 1 POST + 0 GET |
| Sesion expirada | Los timers seguian consultando tras 401 | Tras detectar el primer 401, 0 consultas nuevas automaticas o por campana hasta login/recarga |
| Fallo de red en Notificaciones visibles | 2 intentos por minuto, sin deduplicar | Como maximo un intento por ciclo de 5 minutos; abrir campana permite reconsultar |
| Metadata activa, un minuto visible | 15 GET (intervalo 4 s) | 15 GET |
| Export activo en editor, un minuto visible | 24 GET (2,5 s) + 1 inicial | 24 GET + 1 inicial |
| Export activo en Procesamientos, un minuto visible | 20 GET (3 s) + 1 inicial | 20 GET + 1 inicial |
| Metadata/editor/Procesamientos activos, un minuto oculto | 15 / 24 / 20 GET programados | 0 GET nuevos en los tres casos |
| Volver visible con trabajo activo | El polling de video ya seguia activo | 1 GET inmediato por consulta activa; no duplica una peticion pendiente |
| Video terminado, fallido o lista de exports vacia | Metadata y exports terminales detenian su timer | 0 GET adicionales; tambien se prueba la lista vacia |
| Fallos consecutivos de red en video | Reintentos sin limite al intervalo normal | Intervalo exponencial, tope 60 s, se detiene al quinto fallo; boton de consulta manual |

Para la copia local `3f438bb`, los estimados anteriores eran 2 GET/10 minutos en cabecera y 4 GET/10 minutos en Notificaciones visibles, con 0 ocultos; esto explica la diferencia con main y con el contexto inicial. El cambio final conserva los 5 minutos del ajuste local para la unica vista que necesita polling.

Una peticion ya enviada puede terminar despues de ocultar la pestana o de recibir otro 401. Se impiden nuevas peticiones; no se intenta cancelar escrituras ni uploads. Los GET de estado tienen timeout cliente de 15 segundos. Cancelar fetch libera el cliente, pero no garantiza cancelar trabajo que Apache/PHP ya haya comenzado.

## Comportamiento y limites

- Se conserva el HTML inicial, incluidas las notificaciones recientes y su contador. No hay un GET adicional al montar la cabecera o la vista de Notificaciones.
- Solo Notificaciones crea su timer. Una lectura pendiente se comparte o se espera antes de consultar otra lista. El contador proviene del mismo GET o POST; no se consulta por separado.
- Los filtros conservan `all`, `unread`, `read`, limite 50 en pagina y 5 en campana. Solo la pagina sin filtro puede abastecer la lista compacta; una lista filtrada nunca reemplaza esa lista completa. Sus contadores si se comparten.
- Antes de escribir se invalida la revision de las lecturas; una respuesta vieja no puede revertir un leido ni renderizar un filtro diferente. Se serializan las escrituras. Un POST fallido conserva los datos visibles y muestra el error.
- Los botones de video `Actualizar estado...` aparecen ante un fallo y reinician la consulta del mismo trabajo, sin crear otro export ni reanalizar el archivo. Tras cinco fallos, cambiar de pestana no reinicia el presupuesto; el usuario puede pulsar el boton o recargar.
- El 401 se comparte entre video y notificaciones. Volver a iniciar sesion y cargar de nuevo la pagina reinicializa el estado; cambiar de visibilidad no lo reinicia. Un 403 de CSRF no se confunde con sesion expirada.
- No se agregan dependencias, servicios, migraciones ni cambios de proveedor. La funcion de polling es JavaScript nativo y se carga antes de `app.js` mediante scripts `defer` en orden.

## Recursos publicos y Vercel Services

Se conserva `vercel.json`: servicio `web`, runtime `container`, rewrite global y cron diario originales. No se supone que `public/` dentro de un Dockerfile se extraiga automaticamente como salida estatica de Vercel.

La mejora aplicada es HTTP: una configuracion Apache compartida entre Docker Compose y `Dockerfile.vercel` emite `Cache-Control: public, max-age=300, s-maxage=3600` solo para extensiones CSS, JS, imagenes y fuentes bajo `/public/assets`, en respuestas 200/304. Apache conserva ETag y Last-Modified. Los JS/CSS del layout ya usan `?v=filemtime`; el nuevo script tambien lo usa. No se utiliza `immutable`, ya que se pueden solicitar URLs sin version.

Las respuestas PHP envian `private, no-store` desde SecurityHeaders (el limitador de sesion PHP puede reemplazarlo por `no-store, no-cache, must-revalidate`). El endpoint protegido de jobs tambien lleva `private, no-store`. Ninguna regla de cache alcanza `/api`, paginas autenticadas, `storage` ni streaming/descargas privadas. Los assets no crean sesion ni cookies.

Compatibilidad comprobada: construccion y sintaxis de Apache de las imagenes de Compose y Vercel; HTTP real en ambos contenedores para assets y rutas privadas. Las respuestas publicas cumplen el mecanismo documentado de cache de respuestas de Functions, que tambien incluye el runtime container. El primer MISS seguira llegando a Apache. **El HIT de CDN queda pendiente de verificar en un deployment de Vercel reactivado**, porque no se hizo despliegue. No se afirma que se hayan publicado assets estaticos fuera del contenedor.

Referencias de plataforma consultadas, sin cambiar la arquitectura propia:

- [Vercel Services](https://vercel.com/kb/guide/vercel-services): services y rewrites.
- [Docker en Vercel](https://vercel.com/kb/guide/docker): contenedores como Functions con Fluid compute.
- [Cache-Control](https://vercel.com/docs/caching/cache-control-headers): `s-maxage`, `no-store` y prioridad de cabeceras.

## Base de datos y trabajos programados

En la carga inicial de Notificaciones/Todas, la campana toma los primeros 5 de los 50 elementos ya consultados. Esto elimina un SELECT de listado, manteniendo una sola consulta de contador. En los filtros Leidas/No leidas se conserva la consulta compacta sin filtro porque representa datos distintos. Las listas usan JOINs del repositorio existente; no se introduce un SELECT por notificacion. El resumen de Dashboard pide no leidas con otro limite y se conserva.

PDO limita la conexion a 5 segundos por defecto; `DB_CONNECT_TIMEOUT` acepta 1..60. Se propaga al web y worker en ambos Compose y al entorno que carga Cron. No hay conexiones persistentes nuevas, ni cambios de SQL, indices o timeouts de transacciones. Este parametro no limita la duracion de cada consulta SQL: esperas de locks, streaming largo y backlog del cron siguen requiriendo observarse en produccion. Si se configura sesion en BD, cada peticion tambien tiene coste de lectura/escritura y limpieza de sesiones en el flujo actual; reducir peticiones reduce ese trabajo sin cambiar su semantica.

| Proceso conservado | Frecuencia actual | Donde incurre el coste |
| --- | --- | --- |
| Vercel `/api/jobs?job=process-reminders` | `0 0 * * *`, una vez al dia, UTC | Una invocacion HTTP que espera el PHP CLI; su duracion y backlog pueden consumir memoria provisionada de Vercel |
| Compose: recordatorios, actividad de notificaciones, metadata, exports, scheduler de descuentos | Cada minuto (1440 oportunidades por dia y proceso) | Contenedor worker y MariaDB del entorno Compose; no genera por si mismo invocaciones HTTP de Vercel |
| Compose: gastos recurrentes | Diario 00:11, zona del worker | Worker/BD |
| Compose: avisos de gastos | Cada hora al minuto 17 | Worker/BD |
| Compose: limpieza de video | Diario 02:17, zona del worker | Worker/filesystem/BD |

El Dockerfile de Vercel no inicia el worker de Compose ni instala FFmpeg. No se cambia esa distribucion. Despertar el scheduler no equivale a ejecutar FFmpeg o consultar collectors siempre: los workers revisan pendientes y el scheduler respeta `next_run_at`. Procesar un export activo puede ser mucho mas costoso que comprobar que no hay trabajo. No se cambia el cron diario ni se intenta ejecutar workers continuos en Vercel Hobby.

## Verificacion reproducible

En WSL, desde la raiz, con Docker Desktop disponible. Para evitar usar datos reales, se uso un proyecto de Compose aislado y las credenciales de ejemplo ya incluidas en el repositorio:

```sh
docker compose -p mi-central-polling-check --env-file .env.example up -d --build web db worker
docker compose -p mi-central-polling-check --env-file .env.example exec web php bin/migrate.php
docker compose -p mi-central-polling-check --env-file .env.example exec web php bin/seed.php
docker compose -p mi-central-polling-check --env-file .env.example exec web apache2ctl -t
docker compose -p mi-central-polling-check --env-file .env.example exec web sh -lc 'for test in tests/unit/*.php; do php "$test" || exit 1; done'
```

Se ejecutaron con exito las 9 pruebas unitarias y estas 13 integraciones:

- En `web`: `database_connection.php`, `notifications_center.php`, `session_database.php`, `http_auth_flow.php`, `reminder_worker.php`, `organization_reminders.php`, `expenses_notifications.php`, `cache_headers.php`, `video_streaming.php`, `video_uploads.php`.
- En `worker`: `video_worker_environment.php`, `video_metadata.php`, `video_exports.php`.

Ejemplo para cada integracion (sustituir archivo/servicio de la lista):

```sh
docker compose -p mi-central-polling-check --env-file .env.example exec web php tests/integration/cache_headers.php
docker compose -p mi-central-polling-check --env-file .env.example exec worker php tests/integration/video_metadata.php
```

La prueba de metadata necesita que el worker haya terminado de iniciar y cargado su crontab. Los primeros intentos en web fallaron por ausencia esperada de FFmpeg, y uno al iniciar worker fallo antes de cargar Cron; las ejecuciones finales en worker pasaron. No se modificaron esas pruebas para omitir requisitos.

Para el navegador, servir el repositorio **solo en localhost**, sin publicar este servidor ni las fixtures:

```sh
python3 -m http.server 8765 --bind 127.0.0.1
# Abrir http://127.0.0.1:8765/tests/browser/polling.html
```

La pagina informa `ALL PASSED: 14`. Se ejecuto en Chrome headless, tambien accesible manualmente. Incluye: conteos de consultas, filtros y limites, deduplicacion, respuesta antigua despues de POST/cambio de filtro, POST fallido, contador inmediato, visibilidad inicial y posterior, listas vacias, estados terminales, 401 compartido, red caida, timeout y recuperacion manual. Se simula la API solo en las fixtures; las integraciones PHP comprueban aparte API real, sesion, CSRF y ownership.

Tambien se verificaron: sintaxis de los tres JS; lint PHP de `app`, `config`, `public`; `docker compose config -q` en desarrollo y produccion; construccion de ambas imagenes Compose y de `Dockerfile.vercel`; cabeceras de la imagen Vercel levantada localmente en 8081 (asset 200 cacheable, API 401 no-store, job 403 no-store); diff sin errores de espacios respetando CRLF originales.

```sh
docker build -f Dockerfile.vercel -t mi-central-vercel-polling-check .
git -c core.whitespace=cr-at-eol diff --check
```

`make test` incluye la nueva integracion de cabeceras. No se ejecuto toda la suite de modulos ajenos. Los tests de navegador no requieren Node, paquetes npm ni cambios en el backend. Las pruebas no cargan FFmpeg durante una invocacion HTTP de Vercel.

## Archivos del cambio

- Consultas/interfaz: `public/assets/js/app.js`, nuevo `public/assets/js/polling.js`, `app/Views/layout/footer.php`, `app/Views/pages/notifications.php`, `public/index.php`.
- Cache: nuevo `docker/apache/public-assets.conf`, `docker/php/Dockerfile`, `Dockerfile.vercel`, `app/Http/SecurityHeaders.php`, `public/api/jobs.php`.
- Conexion: `app/Database/Connection.php`, `config/database.php`, `.env.example`, `compose.yaml`, `compose.prod.yaml`, `docker/worker/start-cron.sh`.
- Pruebas: `tests/browser/polling.html`, `tests/browser/fixture.html`, `tests/browser/polling.js`, `tests/integration/cache_headers.php`, `tests/integration/notifications_center.php`, `Makefile`.
- Documentacion: `docs/ARCHITECTURE.md`, este documento.

## Reactivacion y medicion

El cambio se prepara sobre la rama de produccion main. Verificar en Vercel que el repositorio conectado sea IsaiasACF/Mi-Central y que el deployment seleccionado contenga el SHA corregido.

Cuando Vercel reactive el equipo:

1. Integrar el commit revisado y desplegar ese SHA con la configuracion Services actual y `Dockerfile.vercel`. Conservar variables secretas existentes; `DB_CONNECT_TIMEOUT=5` es opcional porque ya es el default. No hacen falta migraciones ni seeds nuevos.
2. Validar login, campana, filtros, marcar leida/todas y las rutas privadas. En DevTools Network, repetir los escenarios de la tabla. Los estados activos de video requieren el worker/almacenamiento que ya utilice la instalacion; ocultar el navegador pausa la consulta, no el trabajo.
3. Solicitar dos veces la misma URL de asset (incluida su version) desde la misma region; revisar `x-vercel-cache` MISS/HIT y que el segundo acceso no genere otra ejecucion del contenedor. Comprobar `Cache-Control`, ETag y que no haya Set-Cookie. Si no hay HIT, revisar elegibilidad/estado de cache del runtime sin ampliar reglas a rutas privadas. Probar paginas/API autenticadas con dos sesiones para asegurar que siguen fuera de cache compartida.
4. En **Usage del equipo**, filtrar por proyecto y comparar ventanas equivalentes antes/despues: Fluid Provisioned Memory (GB-h), Active CPU, invocaciones, solicitudes a origen y transferencia. Separar otros proyectos y el dia del despliegue; no atribuir todo el 263% a Mi Central. Registrar fecha, SHA, trafico y cantidad aproximada de pestañas activas para interpretar cambios.
5. En **Observability del proyecto**, desglosar `/api/notifications.php`, `/api/video/files.php`, `/api/video/exports.php`, `/api/jobs` y `/assets/*`: volumen, duracion p50/p95/max disponible, errores/401/5xx, timeouts, concurrencia y ejecuciones del servicio `web`. Correlacionar peticiones largas con logs de BD/locks y con el cron diario. Seguir el avance diario de GB-h, ademas del acumulado mensual; menos GET no garantiza una proporcion fija de ahorro con concurrencia Fluid.
6. Confirmar que el cron diario termino correctamente una vez y que el worker de Compose conserva sus tareas y sus propios logs. Evitar reintentar manualmente trabajos sin revisar primero su estado.
