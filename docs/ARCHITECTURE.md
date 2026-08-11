# Arquitectura

## Enfoque general

Mi Central sera una **aplicacion monolitica modular**.

Esto significa que todos los modulos viviran dentro de una misma aplicacion, compartiran una base de datos principal y se ejecutaran como una sola unidad desplegable mediante Docker Compose.

No se usaran microservicios inicialmente.

## Flujo web principal

```text
Navegador
-> Apache
-> PHP
-> MariaDB
```

## Procesos auxiliares

```text
PHP CLI / Cron
-> workers
-> FFmpeg / recolectores / recordatorios
```

## Modulos

Los modulos deben mantenerse independientes dentro de la misma aplicacion.

Cada modulo debe agrupar su propia logica, vistas, validaciones y operaciones relacionadas, evitando acoplarse innecesariamente con otros modulos.

Cuando exista funcionalidad compartida, debe ubicarse en componentes comunes claramente justificados y documentados.

## Base de datos

La aplicacion usara una base de datos principal en MariaDB 11.8.

Los modulos podran relacionarse mediante claves foraneas cuando corresponda, manteniendo integridad referencial y evitando duplicacion innecesaria de informacion.

## API interna

La aplicacion podra exponer una API interna en PHP que responda JSON para interacciones dinamicas del frontend o acciones asincronas.

Esta API interna no implica separar el backend en otro servicio.

## Frontend

El frontend inicial usara:

- HTML5.
- CSS3.
- JavaScript nativo.

No se usaran React ni Vue inicialmente.

## Layout autenticado

La interfaz autenticada usa componentes PHP nativos reutilizables en `app/Views/layout/`:

- `header.php`;
- `sidebar.php`;
- `footer.php`.

Las pantallas base viven en `app/Views/pages/`. `public/index.php` actua como entrada autenticada y mantiene una unica estructura principal de contenido.

Los componentes visuales reutilizables viven en `app/Views/components/`. Su responsabilidad es concentrar markup comun de la interfaz, escapar texto dinamico por defecto y evitar duplicacion en vistas como el Dashboard.

El Dashboard obtiene sus datos desde `modules/Dashboard/DashboardSummaryService.php`. El flujo previsto es:

```text
modulos futuros / datos
-> DashboardSummaryService
-> vista Dashboard
-> widgets reutilizables
```

Mientras los modulos no existan, el servicio devuelve colecciones vacias, conteos coherentes y mensajes de estado vacio. La excepcion actual es Bandeja rapida, que obtiene tareas pendientes sin espacio (`organization_tasks.space_id IS NULL`) para mostrar cantidad y algunos elementos recientes. Hoy y Proximamente integran tareas con fecha, vencimientos y recordatorios pendientes; para recordatorios recurrentes solo consideran `next_remind_at`, no todas las repeticiones futuras. Los recordatorios se rotulan explicitamente como `Recordatorio:` para no confundirlos con el vencimiento propio de una tarea. Amigos en la U aporta un resumen de estado actual y un widget pequeno de proxima coincidencia academica usando `CoincidenceService`, sin duplicar la logica de horarios efectivos.

## Backend de tareas de Organizacion

El backend interno de Organizacion mantiene el flujo:

```text
HTTP JSON autenticado
-> servicio del modulo
-> repositorio del modulo
-> PDO / MariaDB
```

Los endpoints internos principales viven en:

```text
/api/organization/tasks.php
/api/organization/projects.php
/api/organization/notes.php
/api/organization/reminders.php
```

Operaciones de tareas disponibles:

- `GET /api/organization/tasks.php` lista tareas del usuario autenticado con filtros basicos.
- `GET /api/organization/tasks.php?id=ID` obtiene una tarea propia.
- `POST /api/organization/tasks.php` crea una tarea.
- `PATCH /api/organization/tasks.php?id=ID` o `PUT ...` actualiza campos editables.
- `POST /api/organization/tasks.php?id=ID&action=complete` completa una tarea.
- `POST /api/organization/tasks.php?id=ID&action=reopen` reabre una tarea.
- `DELETE /api/organization/tasks.php?id=ID` elimina una tarea propia.

Operaciones de proyectos disponibles:

- `GET /api/organization/projects.php` lista proyectos propios con progreso calculado.
- `GET /api/organization/projects.php?id=ID` obtiene un proyecto propio.
- `POST /api/organization/projects.php` crea un proyecto.
- `PATCH /api/organization/projects.php?id=ID` o `PUT ...` actualiza campos editables.
- `POST /api/organization/projects.php?id=ID&action=complete` marca un proyecto como completado.
- `POST /api/organization/projects.php?id=ID&action=archive` archiva un proyecto.
- `DELETE /api/organization/projects.php?id=ID` elimina un proyecto propio.

Operaciones de notas disponibles:

- `GET /api/organization/notes.php` lista notas propias con filtro simple de espacio.
- `GET /api/organization/notes.php?id=ID` obtiene una nota propia.
- `POST /api/organization/notes.php` crea una nota.
- `PATCH /api/organization/notes.php?id=ID` o `PUT ...` actualiza titulo, contenido y espacio.
- `DELETE /api/organization/notes.php?id=ID` elimina una nota propia.

Operaciones de recordatorios disponibles:

- `GET /api/organization/reminders.php` lista recordatorios propios con filtros basicos.
- `GET /api/organization/reminders.php?id=ID` obtiene un recordatorio propio.
- `POST /api/organization/reminders.php` crea un recordatorio.
- `PATCH /api/organization/reminders.php?id=ID` o `PUT ...` actualiza campos editables.
- `POST /api/organization/reminders.php?id=ID&action=complete` marca un recordatorio como completado.
- `POST /api/organization/reminders.php?id=ID&action=dismiss` descarta un recordatorio.
- `POST /api/organization/reminders.php?id=ID&action=stop` detiene una recurrencia.
- `DELETE /api/organization/reminders.php?id=ID` elimina un recordatorio propio.

Las escrituras requieren sesion autenticada y token CSRF. El navegador no envia ni controla `user_id`; siempre se toma desde la sesion. `TaskService` valida entrada, estados, prioridades, fechas y ownership de `space_id`, `category_id`, `project_id` y `parent_task_id`. `TaskRepository` ejecuta consultas preparadas y limita todas las operaciones por `user_id`.

`ProjectService` valida que cada proyecto pertenezca a un espacio propio del usuario. El progreso de proyectos no se almacena en base de datos: `ProjectRepository` lo calcula con las tareas asociadas como `tasks_completed`, `tasks_total` y `progress_percent`. Al eliminar un proyecto, las tareas se conservan y `project_id` queda `NULL` segun la FK existente.

Las tareas pueden asociarse a proyectos mediante `project_id`. Cuando se crea una tarea desde un proyecto, `TaskService` asigna o exige un `space_id` coherente con el espacio del proyecto. La interfaz no vuelve a pedir espacio en ese contexto y el backend rechaza un espacio distinto. Si cambia el espacio del proyecto, `ProjectService` sincroniza las tareas asociadas para mantener la herencia.

Las subtareas usan `parent_task_id`; la capa de servicio impide autorreferencias, ciclos evidentes y relaciones de otro usuario. La interfaz muestra un nivel visual de subtareas dentro del detalle del proyecto/tarea correspondiente, aunque el modelo puede almacenar jerarquias mas profundas para uso futuro.

Las tareas tienen tres campos temporales:

- `starts_at`: inicio opcional del periodo en que ocurre o se realiza la tarea;
- `ends_at`: fin opcional del periodo;
- `due_at`: fecha limite opcional.

`TaskService` valida fechas y rechaza `ends_at` anterior a `starts_at`. Los filtros rapidos actuales de Tareas siguen usando `due_at`. El Calendario combina `starts_at`, `ends_at` y `due_at`.

La politica temporal del proyecto es: el usuario introduce y visualiza `DATETIME` en `America/Santiago`, y la base almacena esos valores en UTC. `App\Support\DateTimeHelper` centraliza la conversion de entrada local a UTC, la conversion de UTC a local, el valor de "ahora" y el formato para controles `datetime-local`. Los campos `DATE` puros de proyectos, como `starts_on` y `due_on`, no pasan por conversion de zona horaria.

No hay SQL en vistas ni en el endpoint HTTP. La API es interna de la aplicacion y devuelve JSON con mensajes seguros, sin trazas ni detalles SQL.

`NoteService` mantiene notas como registros simples separados de tareas y proyectos. Una nota tiene titulo, contenido y espacio opcional; no tiene estado, prioridad, fechas ni completado. `NoteRepository` limita todas sus consultas por `user_id`.

`ReminderService` mantiene recordatorios dentro de Organizacion. Un recordatorio puede estar asociado a una tarea, a un proyecto o ser independiente, pero no puede apuntar simultaneamente a tarea y proyecto. La capa de servicio valida que `task_id` y `project_id` pertenezcan al mismo `user_id` autenticado. `remind_at` es obligatorio, se guarda en UTC y se presenta usando `America/Santiago`. Los estados disponibles son `pending`, `completed` y `dismissed`.

Los recordatorios pueden ser no recurrentes o recurrentes con frecuencia diaria, semanal, mensual o anual e intervalo entero mayor o igual a 1. La tabla guarda una sola definicion recurrente, no una fila por cada ocurrencia futura. `remind_at` representa la fecha base de la definicion y `next_remind_at` representa la ocurrencia activa que deben mostrar Organizacion y Dashboard. `recurrence_until`, cuando existe, limita la serie y no permite una siguiente ocurrencia posterior a esa fecha.

Completar o descartar una ocurrencia recurrente no marca permanentemente la definicion como completada si existe una siguiente ocurrencia; en ese caso `next_remind_at` avanza y el recordatorio sigue `pending`. Si ya no hay siguiente ocurrencia por `recurrence_until`, se marca con el estado terminal correspondiente. Detener una recurrencia es una accion explicita que desactiva la serie. Esta fase no implementa envios, Push, PWA ni centro completo de notificaciones.

La politica para recurrencias mensuales y anuales con fechas especiales es usar el ultimo dia valido del mes cuando el dia original no existe. Por ejemplo, una recurrencia mensual del 31 de enero avanza al 28 o 29 de febrero segun corresponda; una recurrencia anual del 29 de febrero avanza al 28 de febrero en anos no bisiestos.

`workers/process-reminders.php` procesa automaticamente recordatorios vencidos desde CLI. Busca recordatorios `pending` cuya ocurrencia efectiva (`COALESCE(next_remind_at, remind_at)`) sea menor o igual a la hora UTC actual obtenida mediante `DateTimeHelper`. Por cada ocurrencia vencida crea una notificacion interna `reminder_due` en `notifications` y luego cierra o avanza el recordatorio segun corresponda.

La idempotencia se basa en una restriccion unica de `notifications` sobre `reminder_id`, `type` y `scheduled_at`, donde `scheduled_at` es la ocurrencia programada. El worker ademas procesa cada recordatorio dentro de una transaccion y usa `SELECT ... FOR UPDATE` para evitar duplicados ante ejecuciones simultaneas.

Si el worker estuvo detenido, no genera una notificacion por cada ocurrencia perdida. Crea como maximo una notificacion atrasada para la ocurrencia vencida actual y avanza `next_remind_at` hasta la siguiente ocurrencia futura, o finaliza la recurrencia si `recurrence_until` ya no permite mas ejecuciones.

El centro visual de notificaciones internas usa el flujo:

```text
Reminder
-> worker
-> notifications
-> NotificationService
-> campana / Dashboard / vista de notificaciones
```

`NotificationService` lista notificaciones propias, cuenta no leidas y marca una o todas como leidas. La campana del header muestra las recientes y el contador de no leidas; la vista `/index.php?section=notifications` permite filtrar Todas, No leidas y Leidas sin agregar una entrada nueva al sidebar. Las escrituras pasan por la API interna `/api/notifications.php` con sesion autenticada, CSRF y restricciones por `user_id`. Esta fase no implementa Push, email, PWA ni WebSockets.

`CalendarService` no crea ni duplica datos. Construye una vista de calendario desde tareas y proyectos existentes:

- tareas programadas mediante `starts_at` y `ends_at`;
- vencimientos mediante `due_at`;
- proyectos mediante `starts_on` y `due_on`;
- tareas internas de proyectos cuando tienen fechas.

Los elementos del calendario enlazan al flujo existente de edicion/detalle de tareas o proyectos. No existe un editor especifico de calendario.

`organization_events` se conserva en base de datos pero no se usa por ahora. La Fase 2 no implementa Eventos como tipo separado porque las tareas ya soportan temporalidad suficiente mediante `starts_at`, `ends_at` y `due_at`.

## Amigos en la U

El modulo Amigos en la U modela amigos y horarios ingresados manualmente. No rastrea ubicacion real, no usa GPS, no consulta mapas y no crea cuentas para amigos. Las vistas futuras estimaran donde deberia estar una persona segun su horario academico registrado.

El modelo inicial usa:

- `friends`: amigos pertenecientes al usuario autenticado;
- `friend_schedule_entries`: bloques recurrentes semanales de cada amigo;
- `friend_schedule_exceptions`: excepciones puntuales de amigos para cambios, ausencias o cancelaciones;
- `user_schedule_entries`: horario universitario propio del usuario, separado de `friends` para no crear una persona ficticia;
- `user_schedule_exceptions`: excepciones puntuales del horario propio.

`FriendScheduleService` y `FriendScheduleRepository` entregan operaciones minimas de consulta/creacion para pruebas y uso futuro. El ownership de horarios de amigos se deriva siempre por la cadena `users -> friends -> friend_schedule_entries`; aunque las entradas de horario no tienen `user_id` directo, toda consulta debe unir con `friends.user_id`. El horario propio usa `user_schedule_entries.user_id` directamente.

Los horarios semanales usan `weekday` con lunes = 1 y domingo = 7. `starts_at` y `ends_at` son campos `TIME` locales academicos, interpretados en `America/Santiago`, sin conversion a UTC. `valid_from`, `valid_until` y `exception_date` son `DATE` puros y tampoco se convierten. Esta politica es distinta a los `DATETIME` de tareas, recordatorios y notificaciones.

La base no prohibe solapamientos entre clases. Una fase posterior podra advertir conflictos al usuario sin bloquear necesariamente el guardado.

La gestion de amigos usa el flujo:

```text
interfaz/API autenticada
-> FriendService
-> FriendRepository
-> PDO / MariaDB
```

La resolucion de horario efectivo se centraliza en `FriendScheduleResolver`:

```text
horario recurrente semanal
-> filtro por weekday
-> vigencia valid_from / valid_until
-> excepcion puntual por fecha
-> horario efectivo
```

`cancelled` muestra la clase atenuada/cancelada pero no cuenta como clase activa. `absent` muestra que la persona no asistira y tampoco cuenta como presencia. `modified` reemplaza solo para esa fecha los campos indicados, como hora, sala, ramo, codigo o campus, sin sobrescribir el bloque recurrente. Esta salida efectiva es la fuente para Semana, Ahora, Hoy, Dashboard y queda preparada para coincidencias futuras.

`CoincidenceService` calcula coincidencias de actividad academica simultanea reutilizando exclusivamente el horario efectivo:

```text
ScheduleResolver
-> bloques academicos efectivos
-> interseccion temporal Mi horario / amigos activos
-> coincidencias individuales y grupales
```

Una coincidencia existe cuando un bloque academico efectivo propio se solapa temporalmente con un bloque academico efectivo de un amigo activo. Los huecos libres comunes, como recreos entre clases, no forman parte de esta vista. `cancelled` y `absent` no generan coincidencias; `modified` usa la hora y datos modificados para esa fecha. "Mismo campus" significa que ambos bloques tienen campus conocido y coincidente de forma normalizada, sin afirmar ubicacion fisica real.

La pestana `Coincidencias` consume `CoincidenceService` desde `public/index.php` y renderiza resultados server-side, sin recalcular disponibilidad en JavaScript. Permite consultar una fecha local, amigo especifico y filtro de mismo campus. Los resultados separan coincidencias individuales y grupales; los intervalos de hoy que ya terminaron se muestran atenuados y la proxima coincidencia se calcula usando `America/Santiago`.

La integracion manual con Organizacion usa el flujo:

```text
ScheduleResolver
-> CoincidenceService
-> vista Coincidencias
-> usuario selecciona Crear tarea
-> CoincidenceTaskPrefillService valida/recalcula la coincidencia
-> formulario existente de tareas
-> OrganizationTaskService
-> Organizacion / Calendario
```

Una coincidencia es informacion inferida desde horarios academicos. Una tarea es una decision explicita del usuario. Por eso las coincidencias no aparecen automaticamente en el Calendario de Organizacion ni crean recordatorios; solo se convierten en tareas si el usuario revisa y guarda el formulario de Organizacion. El espacio sugerido se busca por el `slug` `amigos` entre los espacios propios del usuario, sin depender de IDs fijos.

La pantalla vive en `/index.php?section=friends` y mantiene una sola entrada principal en el sidebar: Amigos en la U. Dentro de la pantalla existe navegacion interna simple:

- Ahora;
- Hoy;
- Semana;
- Coincidencias;
- Amigos;
- Horarios.

El endpoint interno `/api/friends/friends.php` permite listar, crear, editar, activar, desactivar y eliminar amigos propios. El endpoint `/api/friends/schedule.php` gestiona bloques semanales tanto para `friend_schedule_entries` como para `user_schedule_entries` usando `target_type=user|friend`; con `action=exceptions` gestiona excepciones del mismo destino. Las escrituras requieren CSRF y nunca aceptan `user_id` desde el navegador.

El editor semanal representa bloques entre 08:00 y 21:00 sin librerias externas de calendario. En desktop muestra una grilla de lunes a domingo; en movil muestra selector de dia y el detalle vertical del dia seleccionado. Los bloques de amigos usan `effective_campus`: si la entrada no tiene campus propio, se muestra `friends.default_campus` sin duplicarlo en la tabla de horario.

Los solapamientos de bloques del mismo horario se permiten. `FriendScheduleService` agrega una advertencia `Este bloque se superpone con otra clase del horario.` cuando detecta cruce de dia/hora y periodos de vigencia compatibles, pero no impide guardar. El filtro por defecto muestra bloques vigentes para la fecha local actual; la opcion "Ver todos los bloques" permite editar horarios fuera de vigencia.

`FriendPresenceService` calcula vistas Ahora y Hoy usando `America/Santiago`, horarios ingresados manualmente y el horario efectivo resuelto. Ahora clasifica amigos activos como En clase, Libre, No asiste, Cancelada, Sin clases hoy o Fuera del periodo de vigencia, siempre con textos como "Segun horario" para no afirmar ubicacion real. Hoy lista los bloques efectivos del dia y los marca como Finalizada, Ahora, Proxima, Cancelada, No asiste o Modificada. El Dashboard consume un resumen pequeno desde este mismo servicio.

La importacion JSON de horarios se realiza desde la pestana Horarios. El usuario selecciona destino en la interfaz y luego pega o carga un JSON; el JSON no puede incluir `user_id` ni `friend_id`. La API valida todo, muestra previsualizacion, advierte duplicados/solapamientos y solo guarda al confirmar. La referencia del formato esta en `docs/SCHEDULE_IMPORT.md`.

## Interfaz de Organizacion

La pantalla autenticada de Organizacion vive en:

```text
/index.php?section=organization
```

`public/index.php` prepara datos iniciales mediante `SpaceRepository`, `TaskService` y `ProjectService`, y renderiza la vista PHP nativa `app/Views/pages/organization-tasks.php`. La vista muestra filtros, formularios y listas iniciales sin consultar la base de datos directamente.

Las acciones de crear, editar, completar, reabrir y eliminar usan JavaScript nativo en `public/assets/js/app.js` contra las APIs internas de tareas y proyectos. La lista se actualiza con `fetch` y creacion de nodos DOM mediante `textContent`, evitando insertar HTML de usuario. Los filtros principales se conservan como query string para mantener URLs simples y revisables.

Organizacion se maneja con pestanas internas, sin nuevas entradas en el sidebar:

- Tareas;
- Proyectos;
- Notas;
- Recordatorios;
- Calendario.

Tareas es la pestana inicial. Muestra tareas independientes por defecto y excluye tareas asociadas a proyectos y subtareas como elementos principales. Sus filtros combinables son espacio, estado, prioridad y tiempo. Bandeja sigue representando `space_id IS NULL`.

Proyectos tiene sus propios filtros de espacio y estado. Puede abrirse un detalle de proyecto dentro de la misma pantalla para ver informacion del proyecto, rango de fechas, progreso y tareas asociadas.

Notas muestra solo notas y permite crear, editar, eliminar y filtrar por espacio. No mezcla notas con tareas ni proyectos.

Recordatorios muestra recordatorios pendientes, todos o completados/descartados. Pueden crearse desde la pestana interna, desde una tarea o desde un proyecto. Cuando se crean desde tarea/proyecto, la relacion queda fijada por contexto y la interfaz no vuelve a pedir el elemento recordado.

Calendario es una vista funcional de lectura sobre tareas y proyectos existentes. La vista mensual y semanal se renderiza con PHP, CSS y JavaScript nativo, sin librerias externas de calendario ni registros especiales. Las tareas sin ninguna fecha no aparecen en Calendario.

La Bandeja no tiene pagina separada ni espacio propio en base de datos. Una tarea pertenece a Bandeja cuando `space_id` es `NULL`. Organizar una tarea consiste en editarla y asignarle un espacio existente del usuario; al hacerlo deja de aparecer bajo el filtro Bandeja. El Dashboard incluye una captura rapida que crea tareas pendientes normales, sin espacio ni vencimiento, usando la API interna de tareas.

Los assets publicos del layout viven en:

- `public/assets/css/app.css`;
- `public/assets/js/app.js`.

No se usa motor de templates, framework frontend, bundler ni CDN.

## Navegacion interna

La navegacion autenticada se resuelve mediante una lista blanca explicita en `app/Support/Navigation.php`.

La URL inicial es:

```text
/index.php
```

Las secciones internas usan:

```text
/index.php?section=clave-conocida
```

Una seccion desconocida no carga archivos dinamicamente y responde con una pantalla segura de seccion no encontrada. No se usa `include` basado directamente en entrada del usuario.

## Workers

Los procesos en segundo plano se implementaran con PHP CLI y se ejecutaran mediante Cron cuando sea necesario.

Casos previstos:

- Recordatorios.
- Recolectores de descuentos.
- Limpieza de archivos temporales.
- Tareas relacionadas con FFmpeg.

El contenedor `worker` ejecuta Cron en primer plano. La frecuencia inicial para recordatorios y metadata de video es cada minuto:

```text
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-reminders.php
* * * * * . /tmp/mi-central-worker-env.sh; cd /var/www/html && /usr/local/bin/php workers/process-video-metadata.php
```

Cada worker escribe en su propio log:

- `storage/logs/reminders-worker.log`;
- `storage/logs/video-metadata-worker.log`.

Los comandos manuales disponibles son:

```sh
make process-reminders
make process-video-metadata
```

Los logs se pueden seguir con:

```sh
make worker-logs
make video-metadata-logs
```

## Video y FFmpeg

El editor basico de video procesara archivos localmente mediante FFmpeg.

FFmpeg y FFprobe viven dentro del contenedor `worker`; no se instalan en el host WSL ni se exponen como servicio externo. Las rutas se centralizan en `config/video.php` y pueden configurarse con `FFMPEG_BIN` y `FFPROBE_BIN`. En la imagen actual las rutas esperadas son:

- `FFMPEG_BIN=/usr/bin/ffmpeg`
- `FFPROBE_BIN=/usr/bin/ffprobe`

El flujo actual de metadata y preview es:

```text
Upload
-> video_files
-> metadata pending
-> worker
-> FFprobe
-> metadata ready
-> reproductor
-> endpoint Range autenticado
-> archivo original fuera de public
```

`storage/video` esta fuera de `public/` y se comparte entre `web` y `worker` mediante el bind mount del proyecto completo en `/var/www/html`. Los archivos subidos, temporales, trabajos y exportaciones no se sirven directamente por Apache. La estructura preparada es:

- `storage/video/uploads`
- `storage/video/jobs`
- `storage/video/exports`
- `storage/video/temp`

La subida segura de videos sigue este flujo:

```text
Browser
-> formulario multipart autenticado con CSRF
-> VideoService
-> validacion de extension, MIME Fileinfo y tamano
-> storage/video/uploads
-> video_files
```

`VideoService` centraliza las reglas de subida. Solo acepta extensiones de video permitidas, verifica el MIME real mediante Fileinfo, limita el tamano con `VIDEO_MAX_UPLOAD_MB`, genera un nombre fisico aleatorio y conserva el nombre original solo como metadata escapada al renderizar. El navegador nunca envia rutas internas ni `user_id`; las consultas pasan siempre por `video_files.user_id`.

Si el archivo se mueve correctamente pero falla la insercion en base de datos, la aplicacion elimina el archivo recien creado para evitar huerfanos. Si falla el movimiento del upload, no se crea registro. La eliminacion recibe solamente un ID, comprueba ownership, obtiene la ruta desde base de datos y elimina el archivo dentro de `storage/video/uploads`.

`workers/process-video-metadata.php` reserva videos con `metadata_status = 'pending'`, los marca `processing` y ejecuta FFprobe dentro del contenedor `worker` usando `FFPROBE_BIN`. Extrae duracion, resolucion, FPS, codecs, contenedor y bitrate cuando FFprobe los entrega. Un video `ready` no se reprocesa de forma repetida; si FFprobe falla se marca `failed` con un mensaje tecnico acotado y el original queda intacto.

La vista de Video muestra metadata compacta cuando esta `ready`, "Analizando video..." mientras esta pendiente o procesando, y permite reintentar analisis solo para videos propios en estado `failed` mediante API con CSRF. El reintento solo vuelve la metadata a `pending`; FFprobe no se ejecuta desde HTTP.

El reproductor usa HTML5 `<video controls>` para originales probablemente compatibles con navegador. Si el contenedor o codecs no son adecuados para preview directo, la aplicacion informa que el archivo se subio correctamente y queda para una fase futura de conversion/exportacion. No se transcodifica ni se generan derivados en esta fase.

El streaming seguro se sirve desde `/video/stream.php?id=ID`. El endpoint exige sesion, busca `video_files.id + user_id`, resuelve la ruta fisica desde la base de datos, comprueba que permanezca dentro de `storage/video/uploads` y transmite por chunks. Soporta un solo `Range: bytes=...` para seek con respuestas `200`, `206` o `416`; no acepta rutas ni `storage_path` desde el navegador.

El primer editor de video usa el reproductor y streaming existentes. El flujo actual es:

```text
video_files
-> reproductor
-> timeline
-> video.currentTime
-> puntos de corte
-> video_cut_points
```

La timeline es proporcional a `video_files.duration_seconds`, que proviene de FFprobe. El playhead se sincroniza con `video.currentTime` mediante eventos HTML5 del reproductor (`timeupdate`, `seeking`, `loadedmetadata`, `durationchange`). Hacer clic o tap en la timeline asigna `video.currentTime` y deja que el streaming Range existente resuelva el seek.

Los puntos de corte se gestionan por API interna autenticada en `/api/video/cuts.php`. Las lecturas usan `GET`; crear, mover y eliminar usan `POST` con CSRF. `VideoEditorService` valida ownership mediante `video_files.user_id`, exige duracion tecnica disponible, rechaza posiciones fuera de `(0, duration_seconds)` y evita duplicados cercanos. Estas operaciones modifican `video_cut_points` y sincronizan segmentos virtuales persistidos: no ejecutan FFmpeg, no generan derivados y no alteran el archivo original.

El editor mantiene segmentos virtuales persistidos con este flujo:

```text
video_cut_points
-> segmentos fuente
-> video_edit_segments
-> incluir/excluir
-> sort_order
-> getExportSequence()
-> exportacion FFmpeg
```

Por ejemplo, un video de `300` segundos con cortes `60`, `150` y `240` se interpreta como rangos fuente `0-60`, `60-150`, `150-240` y `240-300`. Cada rango se guarda en `video_edit_segments` con `source_start_seconds` y `source_end_seconds`; el reordenamiento solo cambia `sort_order`, nunca los tiempos fuente. Excluir un segmento marca `is_included = 0`, no borra el video original ni los puntos de corte.

`VideoEditorService` centraliza la sincronizacion entre cortes y segmentos. Al agregar un corte divide el segmento fuente afectado; al mover un corte actualiza los limites adyacentes; al eliminarlo fusiona los rangos correspondientes. Cuando un rango fuente permanece igual, conserva `is_included` y `sort_order`. Los rangos nuevos heredan decisiones cuando nacen de un rango anterior; si una fusion combina decisiones incompatibles, el resultado queda incluido solo si todos los rangos fusionados estaban incluidos.

La gestion de segmentos usa `/api/video/segments.php`. Las lecturas usan `GET`; excluir, restaurar y reordenar usan `POST` con CSRF. El backend verifica que todos los IDs pertenezcan al mismo video propio, rechaza duplicados y no acepta `user_id`, rutas ni comandos desde el navegador.

`getExportSequence(videoId, userId)` devuelve solo segmentos incluidos, ordenados por `sort_order`, con pares `source_start_seconds` / `source_end_seconds`. Ese contrato alimenta los jobs de exportacion.

La exportacion real de video usa este flujo:

```text
video_edit_segments
-> getExportSequence()
-> snapshot video_export_segments
-> video_export_jobs
-> worker
-> FFmpeg
-> progreso
-> FFprobe
-> storage/video/exports
-> descarga autenticada
-> retencion/cleanup
```

El editor crea un job mediante `/api/video/exports.php` con sesion, ownership y CSRF. En la misma transaccion se copia la secuencia incluida actual a `video_export_segments`; por eso una exportacion ya creada no cambia si el usuario modifica cortes o reordena segmentos despues. El navegador nunca envia `user_id`, rutas, filtros FFmpeg, codecs ni parametros arbitrarios.

`workers/process-video-exports.php` procesa como maximo un job por ejecucion. Primero evita iniciar otro job si existe uno `processing`, luego reclama atomicamente un `pending`, lo marca `processing`, carga el original desde `video_files`, usa el snapshot del job y genera un MP4 temporal en `storage/video/temp`. Solo si FFmpeg termina correctamente y FFprobe verifica el resultado, mueve el archivo final a `storage/video/exports` y marca el job como `completed`.

La estrategia FFmpeg usa el archivo original como input y genera filtros internos `trim/setpts` para video, `atrim/asetpts` cuando existe audio, y `concat` segun el orden del snapshot. La salida actual es MP4 con H.264 (`libx264`, CRF razonable, `yuv420p`, `+faststart`) y AAC cuando hay pista de audio. No usa `-c copy`, porque los cortes precisos y el reordenamiento no dependen de keyframes.

El progreso real se obtiene desde FFmpeg con `-progress pipe:1` y `-nostats`. El worker parsea `out_time_us` / `out_time_ms`, `out_time`, `speed` y `progress`, calcula `progress_percent` contra `estimated_duration_seconds` y limita el valor a `0..100`. La base se actualiza solo cuando el avance cambia al menos 1% o aproximadamente cada segundo. `completed` queda en 100%; `failed` conserva el ultimo progreso alcanzado.

La interfaz consulta `/api/video/exports.php?video_id=ID` cada 2-3 segundos solo mientras existan jobs `pending` o `processing`; al quedar todos en `completed` o `failed`, detiene el polling. No se usan WebSockets.

La descarga segura se sirve desde `/video/export/download.php?id=ID`. El endpoint exige sesion, busca `video_export_jobs.id + user_id`, requiere `status = completed`, resuelve `output_path` desde BD, valida que el `realpath` permanezca dentro de `storage/video/exports` y transmite el MP4 por chunks con `Content-Disposition: attachment`. No acepta rutas, filenames fisicos ni `user_id` desde el navegador.

Las exportaciones terminadas pueden eliminarse manualmente desde la UI mediante `POST` con CSRF a `/api/video/exports.php?action=delete&id=ID`. El backend valida ownership, borra solo el MP4 exportado y elimina el job con su snapshot en cascada. Nunca borra el original ni `video_cut_points` / `video_edit_segments`.

Si FFmpeg o FFprobe fallan, el job queda `failed`, se guarda un error controlado y se eliminan temporales incompletos. La exportacion no reemplaza, modifica ni borra el archivo original, ni modifica `video_edit_segments`.

`workers/cleanup-video-files.php` corre diariamente por Cron. Elimina temporales antiguos de `storage/video/temp` con patron controlado, sin tocar temporales de jobs `processing`, y elimina exports `completed` cuyo `expires_at` ya vencio. La retencion se configura con `VIDEO_EXPORT_RETENTION_DAYS` y por defecto es 30 dias. La limpieza nunca aplica a `storage/video/uploads`; los originales solo se eliminan por accion explicita del usuario.

El worker conserva Cron para recordatorios, metadata de video, exportaciones y limpieza sin Redis, colas externas ni servicios nuevos. `workers/check-video-environment.php` valida desde CLI que FFmpeg, FFprobe y los directorios de video esten disponibles y sean escribibles.

La ejecucion de FFprobe/FFmpeg no debe construir comandos concatenando valores recibidos del usuario. Las operaciones usan argumentos controlados, rutas generadas internamente y allowlists de acciones permitidas. El navegador nunca debe poder enviar comandos FFmpeg arbitrarios ni rutas del filesystem.

## Video y transcripcion local

La transcripcion de video es una extension local del modulo Video y se ejecuta exclusivamente dentro del contenedor `worker`. No usa APIs externas, no envia audio ni video fuera del equipo y no agrega servicios a Docker Compose.

El flujo actual es:

```text
video_files o video_export_jobs completed
-> video_transcriptions pending
-> worker
-> FFmpeg extrae audio WAV mono 16 kHz PCM s16le
-> storage/video/temp
-> whisper.cpp
-> salida JSON con timestamps
-> WhisperTranscriptParser
-> segmentos start_seconds / end_seconds / text
-> video_transcription_segments
-> full_text
-> completed
```

`whisper.cpp` se instala en la imagen del `worker` desde una version fija, no desde una rama `latest`. La ruta del binario se centraliza con `WHISPER_BIN` y por defecto apunta a:

- `WHISPER_BIN=/usr/local/bin/whisper-cli`

La primera implementacion es CPU-only. No se configura CUDA, ROCm, Metal ni opciones especificas de hardware. La arquitectura deja esa aceleracion para una decision futura, manteniendo por ahora una ejecucion reproducible en Docker Desktop + WSL.

Los modelos Whisper no forman parte de la imagen Docker ni del repositorio Git. Se guardan localmente, fuera de `public/`, en:

- `storage/models/whisper`

La ruta se configura con:

- `WHISPER_MODELS_PATH=/var/www/html/storage/models/whisper`
- `WHISPER_MODEL_PATH=/var/www/html/storage/models/whisper/ggml-base.bin`

El target `make whisper-model MODEL=base` descarga explicitamente un solo modelo permitido (`tiny`, `base`, `small` o `medium`) si no existe, lo deja en `storage/models/whisper` y verifica su SHA1. Esta descarga requiere Internet, pero no ocurre al iniciar Docker ni al ejecutar Cron.

`WhisperService` centraliza binario, modelo, directorios, extraccion WAV, construccion de argumentos y ejecucion con `proc_open()` usando arrays de argumentos. Los comandos de Whisper no viven en controllers ni aceptan comandos, rutas, nombres fisicos de modelo o argumentos arbitrarios desde el navegador.

La salida elegida es JSON de `whisper-cli` con `--output-json`, porque permite obtener timestamps por segmento. `WhisperTranscriptParser` transforma esa salida en una estructura interna con `start_seconds`, `end_seconds` y `text`, tolerando espacios, UTF-8, espanol, ingles y timestamps con decimales.

La creacion de una transcripcion se hace desde el detalle/editor del video con `POST` autenticado y CSRF a `/api/video/transcriptions.php`. El navegador envia exactamente una fuente permitida: `video_id` para el original o `export_job_id` para una exportacion completada. El navegador solo puede elegir `requested_language` entre `auto`, `es` y `en`; el modelo mostrado es informativo y proviene de `WHISPER_MODEL_PATH`. No se acepta `user_id`, rutas, binarios, modelos fisicos ni argumentos CLI desde HTTP. Si ya existe una transcripcion `pending` o `processing` para la misma fuente, se rechaza un segundo job activo. Las transcripciones `completed` anteriores se conservan y una nueva solicitud crea otra fila.

`TranscriptionSourceResolver` centraliza la resolucion segura de fuentes. Para originales valida ownership contra `video_files.user_id` y exige que el `realpath` quede dentro de `storage/video/uploads`. Para exportaciones valida ownership contra `video_export_jobs.user_id`, exige `status = completed`, archivo existente y `realpath` dentro de `storage/video/exports`. Las exportaciones pendientes, en proceso, fallidas o sin archivo no pueden iniciar una transcripcion. Las transcripciones completadas de una exportacion pueden conservarse aunque luego se elimine el MP4, porque texto y segmentos viven en BD y `export_job_id` puede quedar `NULL` por FK `SET NULL`; no se permite reprocesar si la fuente ya no existe.

`workers/process-video-transcriptions.php` procesa como maximo un job por ejecucion. Antes de reclamar un `pending`, comprueba que no exista ningun job `processing`, para mantener una sola transcripcion simultanea en CPU. El worker marca `pending -> processing`, resuelve la fuente mediante `TranscriptionSourceResolver`, extrae un WAV temporal en `storage/video/temp`, ejecuta `whisper.cpp`, parsea JSON, guarda segmentos y `full_text` en una transaccion, marca `completed` con `progress_percent = 100` y elimina temporales. Si falla FFmpeg, Whisper o la validacion, marca `failed`, conserva el ultimo porcentaje real y elimina temporales sin tocar el video original ni la exportacion fuente.

El progreso parte en `0` para `pending`, pasa a un valor conservador de `1` al reclamar el job y a `10` cuando la extraccion WAV termina. La version actual de `whisper.cpp` se ejecuta con `-np` y no entrega progreso estructurado fiable durante la inferencia, por lo que la interfaz muestra "Procesando..." mientras sigue en `processing` y solo queda en `100` al completar. No se inventan porcentajes por timer.

La interfaz consulta `/api/video/transcriptions.php?video_id=ID` cada 3 segundos solo mientras exista una transcripcion `pending` o `processing`; al quedar todas en `completed` o `failed`, detiene el polling. El detalle/editor muestra historial mas reciente primero, conteo de segmentos, fuente original/exportada, estado y acciones minimas de reintento/eliminacion. El boton `Transcribir` usa handlers delegados y el JS se sirve con version por `filemtime` para evitar que una copia cacheada deje el boton sin respuesta despues de desplegar cambios.

La exportacion de una transcripcion completada usa este flujo:

```text
video_transcription_segments
-> TranscriptionExportService
-> TXT / TXT con timestamps / SRT / VTT
-> descarga autenticada
```

`TranscriptionExportService` carga la transcripcion propia, exige `status = completed`, usa segmentos ordenados como fuente de verdad y genera el contenido dinamicamente en UTF-8. TXT limpio no incluye timestamps; TXT con tiempos usa `[HH:MM:SS]`; SRT usa `HH:MM:SS,mmm`; VTT usa `HH:MM:SS.mmm` con encabezado `WEBVTT`. Si no existen segmentos, TXT puede usar `full_text`, pero SRT/VTT y TXT con tiempos se rechazan para evitar archivos corruptos. Los archivos TXT/SRT/VTT no se guardan en `storage/` ni se conservan permanentemente; se transmiten desde `/video/transcription/download.php?id=ID&format=...` con formato validado por allowlist.

La accion "Copiar texto" obtiene la version TXT limpia mediante el mismo endpoint autenticado y la copia con Clipboard API desde JavaScript nativo. No modifica la transcripcion, no crea archivos y no implementa visor sincronizado, busqueda, edicion de subtitulos ni seguimiento del video.

`workers/check-transcription-environment.php` valida desde CLI que `whisper.cpp`, el modelo configurado, FFmpeg, FFprobe, `storage/models/whisper` y `storage/video/temp` esten disponibles. Si el binario existe pero el modelo aun no fue instalado, informa `Model: NOT INSTALLED` y termina con codigo distinto de 0.

## Docker Compose

Todo el proyecto debe poder ejecutarse mediante Docker Compose.

El entorno previsto incluye:

- Apache con PHP.
- MariaDB.
- FFmpeg disponible para procesos PHP/CLI.
- Cron para workers programados.

## Dependencias

Se deben evitar dependencias nuevas salvo que exista una justificacion tecnica clara.

La preferencia inicial es resolver con PHP nativo, JavaScript nativo, MariaDB, FFmpeg y herramientas locales.

## Convenciones iniciales de estructura

- `public/` sera el unico directorio web publico.
- `app/` contendra codigo compartido del nucleo de la aplicacion.
- `app/Views/` contendra componentes visuales PHP reutilizables.
- `modules/` contendra funcionalidades de negocio separadas por modulo.
- `api/` contendra endpoints internos PHP/JSON cuando se implementen.
- `workers/` contendra procesos PHP CLI.
- `database/` contendra migraciones y seeds.
- `storage/` nunca debe exponerse directamente desde Apache.
- `config/` contendra configuracion de aplicacion.
- `docker/` contendra configuracion especifica de contenedores.
